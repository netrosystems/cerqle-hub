<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\AI\Services\LlmGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The short fixed things the bot says, in the customer's language.
 *
 * Shipping a translation table would mean choosing the languages in advance and
 * being wrong for every customer outside that list. Instead each phrase is
 * translated once, on first use, and cached — so the first customer writing in
 * a given language pays a few hundred milliseconds and everyone after them,
 * across every workspace, gets it instantly and for free.
 *
 * The cache is global on purpose: the value is a translation of a Cerqle-owned
 * English constant keyed by a language tag, derived from no tenant's data. The
 * caches that DO hold customer text — query embeddings, search translations —
 * are workspace-scoped instead.
 */
class CannedPhrases
{
    private const MAX_LENGTH = 200;

    /** Whether the most recent lookup had to fall back to its English seed. */
    private bool $fellBack = false;

    /** The language the model reported writing the most recent phrase in. */
    private ?string $lastLanguage = null;

    public function __construct(private LlmGateway $llmGateway) {}

    /**
     * @param  string  $key  One of config('ai.smart_bot.phrases.seeds')
     * @param  string  $language  BCP-47 tag, or 'und' when it is not known yet
     * @param  string|null  $sample  The customer's own message, used when the tag is 'und'
     */
    public function get(string $key, string $language = 'und', ?int $workspaceId = null, ?string $sample = null): string
    {
        // Both readings describe THIS call only. The service is resolved once
        // per request, so a value left over from a previous phrase would be
        // reported against this one and stored on the wrong conversation.
        $this->lastLanguage = null;
        $this->fellBack = false;

        $seed = (string) config("ai.smart_bot.phrases.seeds.{$key}", '');
        if ($seed === '') {
            return '';
        }
        // English needs no round trip, and neither does a turn with no workspace
        // to bill the provider call against.
        if ($workspaceId === null || $this->isEnglish($language)) {
            return $seed;
        }

        // A turn whose language is still unknown is never served from cache and
        // never written to it. The only thing available to key on would be the
        // message's script, and a script is not a language: Latin alone covers
        // English, Spanish, French, Turkish and most of Europe, so one cached
        // phrase would be handed to all of them. That costs one unmetered call
        // on the opening turn, after which the model reports the language and
        // every later turn is a genuine hit.
        // Every conversation opens as 'und', so without this the first greeting
        // of every single conversation pays for a round trip. Remembering which
        // language a given opening line turned out to be makes the second
        // visitor who types "Merhaba" free. This one holds customer text, so
        // unlike the phrase cache it is scoped to the workspace.
        if ($language === ConversationLanguage::UNKNOWN && $sample !== null) {
            $known = $this->cached($this->sampleLanguageKey($workspaceId, $sample));
            if ($known !== null) {
                $language = $known;
                $this->lastLanguage = $known;
            }
        }

        $cacheKey = $this->cacheKey($key, $language);
        if ($cacheKey !== null) {
            $cached = $this->cached($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $translated = $this->translate($seed, $language, $workspaceId, $sample);
        if ($translated === null) {
            // An unavailable provider must never cost the customer their reply:
            // they get the English seed, and diagnostics record that it happened.
            return $seed;
        }

        // Store under the language the model reported rather than the one we
        // asked for. A turn that began with an unknown language still teaches
        // the cache, so the next conversation already known to be Turkish gets
        // the phrase for free instead of paying for the same round trip.
        $storeKey = $cacheKey ?? $this->cacheKey($key, (string) $this->lastLanguage);
        if ($storeKey !== null) {
            $this->store($storeKey, $translated);
        }
        if ($this->lastLanguage !== null && $sample !== null) {
            $this->store($this->sampleLanguageKey($workspaceId, $sample), $this->lastLanguage);
        }

        return $translated;
    }

    /** True when a phrase for this turn had to fall back to its English seed. */
    public function lastUseFellBack(): bool
    {
        return $this->fellBack;
    }

    /**
     * The BCP-47 tag the model reported for the phrase it just wrote, or null.
     *
     * The caller stores this on the conversation, which is what turns the very
     * next turn from a provider round trip into a cache hit.
     */
    public function lastLanguage(): ?string
    {
        return $this->lastLanguage;
    }

    private function translate(string $seed, string $language, int $workspaceId, ?string $sample): ?string
    {
        // Never use the word "translate": a model reads it as an instruction to
        // change language, so an English phrase for an English customer came
        // back in Spanish. Asking it to write the phrase in the customer's
        // language, and to return it unchanged when that is already the
        // language, was verified against every script we could try.
        $instruction = $language === 'und' && $sample !== null
            ? 'Read the SAMPLE MESSAGE to see which language the customer is writing in, then write the PHRASE in that same language. '
                .'If the sample is already in the phrase language, return the phrase unchanged.'
            : "Write the PHRASE in the language identified by the BCP-47 tag {$language}. "
                .'If the phrase is already in that language, return it unchanged.';

        try {
            $response = $this->llmGateway->chatUnmetered(
                $workspaceId,
                array_filter([
                    ['role' => 'system', 'content' => 'You write a customer-support phrase in the language the customer is writing in. '
                        .$instruction
                        .' Answer with the BCP-47 language tag, then a vertical bar, then the phrase, and nothing else. '
                        .'Example: fr|Bonjour ! Comment puis-je vous aider ? '
                        .'No quotes, no explanation, no extra sentence. '
                        .'Keep the phrase under '.self::MAX_LENGTH.' characters and keep the same tone.'],
                    $sample !== null && $language === 'und'
                        ? ['role' => 'user', 'content' => 'SAMPLE MESSAGE: '.mb_substr($sample, 0, 200)]
                        : null,
                    ['role' => 'user', 'content' => 'PHRASE: '.$seed],
                ]),
                ['max_tokens' => 60, 'temperature' => 0.0],
                'ui_phrase',
            );
        } catch (\Throwable $error) {
            $this->fellBack = true;
            Log::info('smart_bot.phrase_fallback', [
                'workspace_id' => $workspaceId,
                'language' => $language,
                'error' => $error->getMessage(),
            ]);

            return null;
        }

        [$tag, $text] = $this->splitTaggedReply(trim($response->content));

        if ($this->acceptable($text)) {
            $this->lastLanguage = $tag;

            return $text;
        }

        $this->fellBack = true;
        Log::info('smart_bot.phrase_rejected', ['workspace_id' => $workspaceId, 'language' => $language]);

        return null;
    }

    /**
     * Splits "tr|Merhaba!" into its tag and its phrase.
     *
     * A model that ignores the format and answers with the phrase alone is not
     * an error: the phrase is still usable, the language is simply unknown and
     * the next turn pays for another round trip rather than getting a wrong tag.
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitTaggedReply(string $raw): array
    {
        $bar = mb_strpos($raw, '|');
        if ($bar === false || $bar > 12) {
            return [null, $raw];
        }

        $tag = trim(mb_substr($raw, 0, $bar));
        $phrase = trim(mb_substr($raw, $bar + 1));
        if ($phrase === '' || ! preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag)) {
            return [null, $raw];
        }

        return [$tag, $phrase];
    }

    /**
     * A phrase is rendered to customers verbatim, so anything that looks like
     * markup, a link or a model explaining itself is discarded.
     */
    private function acceptable(string $text): bool
    {
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return false;
        }
        if (preg_match('/[\p{Cc}]|[<>{}\[\]]/u', $text)) {
            return false;
        }
        $lower = mb_strtolower($text);
        foreach (['http:', 'https:', 'www.', 'bcp-47', 'translation:'] as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Workspace-scoped, because the key is derived from what a customer wrote.
     * Case and surrounding whitespace are ignored so "Merhaba" and "merhaba!"
     * are one entry rather than two.
     */
    private function sampleLanguageKey(int $workspaceId, string $sample): string
    {
        $normalised = preg_replace('/\s+/u', ' ', mb_strtolower($sample)) ?? '';
        // Leading and trailing punctuation carries no language signal, so
        // "Merhaba", "merhaba!" and "¡Hola!" collapse to one entry each.
        $normalised = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $normalised) ?? '';

        return 'ai:lang:w'.$workspaceId.':'.sha1(trim($normalised));
    }

    /** Null when the language is unknown, which means this turn is not cacheable. */
    private function cacheKey(string $key, string $language): ?string
    {
        if ($language === '' || $language === ConversationLanguage::UNKNOWN) {
            return null;
        }

        $version = config('ai.smart_bot.phrases.version', 1);

        return 'ai:phrase:v'.$version.':'.$language.':'.$key;
    }

    private function cached(string $key): ?string
    {
        try {
            $value = Cache::get($key);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(string $key, string $value): void
    {
        try {
            Cache::put($key, $value, now()->addDays((int) config('ai.smart_bot.phrases.cache_days', 90)));
        } catch (\Throwable) {
            // Caching is an optimisation, never a precondition.
        }
    }

    private function isEnglish(string $language): bool
    {
        return $language === '' || strtolower(explode('-', $language)[0]) === 'en';
    }
}
