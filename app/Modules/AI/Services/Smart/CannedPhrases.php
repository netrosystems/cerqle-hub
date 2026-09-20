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

    public function __construct(private LlmGateway $llmGateway) {}

    /**
     * @param  string  $key  One of config('ai.smart_bot.phrases.seeds')
     * @param  string  $language  BCP-47 tag, or 'und' when it is not known yet
     * @param  string|null  $sample  The customer's own message, used when the tag is 'und'
     */
    public function get(string $key, string $language = 'und', ?int $workspaceId = null, ?string $sample = null): string
    {
        $seed = (string) config("ai.smart_bot.phrases.seeds.{$key}", '');
        if ($seed === '') {
            return '';
        }
        // English needs no round trip, and neither does a turn with no workspace
        // to bill the provider call against.
        if ($workspaceId === null || $this->isEnglish($language)) {
            return $seed;
        }

        $cacheKey = $this->cacheKey($key, $language, $sample);
        $cached = $this->cached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $translated = $this->translate($seed, $language, $workspaceId, $sample);
        if ($translated === null) {
            // An unavailable provider must never cost the customer their reply:
            // they get the English seed, and diagnostics record that it happened.
            return $seed;
        }

        $this->store($cacheKey, $translated);

        return $translated;
    }

    /** True when a phrase for this turn had to fall back to its English seed. */
    public function lastUseFellBack(): bool
    {
        return $this->fellBack;
    }

    private function translate(string $seed, string $language, int $workspaceId, ?string $sample): ?string
    {
        $instruction = $language === 'und' && $sample !== null
            ? 'Reply in the same language as the SAMPLE MESSAGE.'
            : "Reply in the language identified by the BCP-47 tag {$language}.";

        try {
            $response = $this->llmGateway->chatUnmetered(
                $workspaceId,
                array_filter([
                    ['role' => 'system', 'content' => 'Translate the customer-support phrase the user sends. '
                        .$instruction
                        .' Reply with the translation only: no quotes, no explanation, no extra sentence. '
                        .'Keep it under '.self::MAX_LENGTH.' characters and keep the same tone.'],
                    $sample !== null && $language === 'und'
                        ? ['role' => 'user', 'content' => 'SAMPLE MESSAGE: '.mb_substr($sample, 0, 200)]
                        : null,
                    ['role' => 'user', 'content' => $seed],
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

        $text = trim($response->content);

        if ($this->acceptable($text)) {
            return $text;
        }

        $this->fellBack = true;
        Log::info('smart_bot.phrase_rejected', ['workspace_id' => $workspaceId, 'language' => $language]);

        return null;
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

    private function cacheKey(string $key, string $language, ?string $sample): string
    {
        $version = config('ai.smart_bot.phrases.version', 1);
        // With no tag yet, key on the message's script so the very first turn in
        // a language still reuses what a previous first turn learned.
        $tag = $language === 'und' ? 'und-'.ScriptHint::of($sample ?? '') : $language;

        return 'ai:phrase:v'.$version.':'.$tag.':'.$key;
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
