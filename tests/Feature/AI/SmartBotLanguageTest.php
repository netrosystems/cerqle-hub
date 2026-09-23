<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\AI\Services\Smart\CannedPhrases;
use App\Modules\AI\Services\Smart\ConversationLanguage;
use App\Modules\AI\Services\Smart\IntentClassifier;
use App\Modules\AI\Services\Smart\QueryEmbedder;
use App\Modules\AI\Services\Smart\ScriptHint;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bot must work for a customer writing in any language, including one
 * nobody anticipated.
 *
 * These tests deliberately assert on the MECHANISM, not on particular
 * languages: there is no "Spanish works" test here, because passing that would
 * prove nothing about the next customer who writes in something else. What is
 * asserted is that no word list is consulted, that guards stop real questions
 * being mistaken for chit-chat, that a phrase is learned once and then free,
 * and that every one of these paths degrades safely instead of failing.
 */
class SmartBotLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.smart_bot.intent.enabled', true);
    }

    public function test_intent_is_recognised_from_vector_similarity_with_no_word_list(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);

        // The exemplar for "thanks" embeds to this vector; the customer's
        // message — in a language this test never names — embeds near it.
        $this->fakeEmbeddings([
            'thank you' => [1.0, 0.0, 0.0],
            'zzz-customer' => [0.99, 0.14, 0.0],
        ]);

        $result = app(IntentClassifier::class)->classify(
            (int) $workspace->id,
            'zzz-customer',
            [0.99, 0.14, 0.0],
        );

        $this->assertSame('thanks', $result['intent']);
        $this->assertSame('embedding', $result['method']);
        $this->assertDatabaseCount('ai_credit_usages', 0);
    }

    public function test_a_long_message_is_never_treated_as_chit_chat(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);
        $this->fakeEmbeddings(['thank you' => [1.0, 0.0, 0.0]]);

        // Identical vector to the greeting exemplar, but far too long to be one.
        $result = app(IntentClassifier::class)->classify(
            (int) $workspace->id,
            'thank you for the earlier reply, but I still need to know whether my order can be redirected to a different address before it ships',
            [1.0, 0.0, 0.0],
        );

        $this->assertNull($result['intent']);
    }

    public function test_a_short_question_is_never_treated_as_chit_chat(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);
        $this->fakeEmbeddings(['thank you' => [1.0, 0.0, 0.0]]);

        $result = app(IntentClassifier::class)->classify((int) $workspace->id, 'can you help me with this?', [1.0, 0.0, 0.0]);

        $this->assertNull($result['intent']);
    }

    public function test_a_message_carrying_figures_is_never_treated_as_chit_chat(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);
        $this->fakeEmbeddings(['thank you' => [1.0, 0.0, 0.0]]);

        $result = app(IntentClassifier::class)->classify((int) $workspace->id, 'thanks 4500', [1.0, 0.0, 0.0]);

        $this->assertNull($result['intent']);
    }

    public function test_yes_and_no_are_only_read_as_answers_when_the_bot_asked_something(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);
        $this->fakeEmbeddings(['no thanks' => [0.0, 1.0, 0.0]]);

        $withoutOffer = app(IntentClassifier::class)->classify((int) $workspace->id, 'nope', [0.0, 1.0, 0.0], false);
        $withOffer = app(IntentClassifier::class)->classify((int) $workspace->id, 'nope', [0.0, 1.0, 0.0], true);

        $this->assertNull($withoutOffer['intent'], 'A bare "no" means nothing without a question.');
        $this->assertSame('decline', $withOffer['intent']);
    }

    public function test_classification_degrades_safely_when_no_embedding_provider_exists(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];

        // No provider configured, so the vector is empty and the caller keeps
        // its existing behaviour instead of erroring.
        $vector = app(QueryEmbedder::class)->vector((int) $workspace->id, 'hello');
        $result = app(IntentClassifier::class)->classify((int) $workspace->id, 'hello', $vector);

        $this->assertSame([], $vector);
        $this->assertNull($result['intent']);
        $this->assertSame('unavailable', $result['method']);
    }

    public function test_a_phrase_is_translated_once_then_served_from_cache_for_free(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);

        $calls = 0;
        Http::fake([
            'api.openai.com/v1/chat/completions' => function () use (&$calls) {
                $calls++;

                return Http::response([
                    'choices' => [['message' => ['content' => 'DE-ACK']]],
                    'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $phrases = app(CannedPhrases::class);
        $first = $phrases->get('thanks_ack', 'de', (int) $workspace->id);
        $second = $phrases->get('thanks_ack', 'de', (int) $workspace->id);

        $this->assertSame('DE-ACK', $first);
        $this->assertSame('DE-ACK', $second);
        $this->assertSame(1, $calls, 'The second use must come from cache.');
        // Learning a language is infrastructure, not a billable answer.
        $this->assertDatabaseCount('ai_credit_usages', 0);
    }

    public function test_a_phrase_falls_back_to_its_english_seed_when_generation_fails(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['error' => 'nope'], 500)]);

        $phrase = app(CannedPhrases::class)->get('thanks_ack', 'xx', (int) $workspace->id);

        $this->assertSame(config('ai.smart_bot.phrases.seeds.thanks_ack'), $phrase);
        $this->assertTrue(app(CannedPhrases::class)->get('thanks_ack', 'en') !== '');
    }

    public function test_a_translation_that_looks_like_markup_or_a_link_is_rejected(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Visit https://example.com for help']]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $phrase = app(CannedPhrases::class)->get('thanks_ack', 'xx', (int) $workspace->id);

        $this->assertSame(config('ai.smart_bot.phrases.seeds.thanks_ack'), $phrase);
    }

    public function test_english_needs_no_provider_call_at_all(): void
    {
        Http::fake();
        $workspace = $this->createWorkspaceContext()['workspace'];

        $phrase = app(CannedPhrases::class)->get('greeting', 'en-GB', (int) $workspace->id);

        $this->assertSame(config('ai.smart_bot.phrases.seeds.greeting'), $phrase);
        Http::assertNothingSent();
    }

    public function test_the_script_hint_names_writing_systems_without_claiming_a_language(): void
    {
        // A cache-key helper only: it never decides what language to reply in.
        $this->assertSame('latn', ScriptHint::of('hola'));
        $this->assertSame('zyyy', ScriptHint::of('12345'));
        $this->assertNotSame(ScriptHint::of('مرحبا'), ScriptHint::of('你好'));
    }

    public function test_a_billable_feature_can_never_use_the_free_path(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);

        $this->expectException(\LogicException::class);
        app(LlmGateway::class)->chatUnmetered(
            (int) $workspace->id,
            [['role' => 'user', 'content' => 'hi']],
            [],
            'rag_reply',
        );
    }

    /** @param array<string, list<float>> $vectors */
    private function fakeEmbeddings(array $vectors): void
    {
        Http::fake([
            // One embedding per input, like a real provider: the exemplars are
            // requested as a single batch, so a fake that answers only the first
            // input would leave every other exemplar without a vector.
            'api.openai.com/v1/embeddings' => function ($request) use ($vectors) {
                $inputs = json_decode($request->body(), true)['input'] ?? [];
                $data = [];
                foreach ((array) $inputs as $input) {
                    $data[] = ['embedding' => $vectors[$input] ?? [0.0, 0.0, 1.0]];
                }

                return Http::response(['data' => $data], 200);
            },
        ]);
    }

    private function withProvider(int $workspaceId): void
    {
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
    }

    public function test_a_conversational_turn_answers_in_the_conversations_language_for_free(): void
    {
        config()->set('ai.smart_bot.conversational_turns', true);
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->withProvider($workspace->id);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            // Learned from an earlier model reply; the tag itself is arbitrary here.
            'ai_language' => 'xx',
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id, 'name' => 'Bot', 'enabled' => true,
        ]);
        $message = new Message;
        $message->body = 'zzz-customer';
        $message->direction = 'in';
        $message->channel = 'webchat';
        $message->setRelation('conversation', $conversation);

        Http::fake([
            'api.openai.com/v1/embeddings' => function ($request) {
                $inputs = json_decode($request->body(), true)['input'] ?? [];
                $data = [];
                foreach ((array) $inputs as $input) {
                    $data[] = ['embedding' => in_array($input, ['thank you', 'zzz-customer'], true)
                        ? [1.0, 0.0, 0.0]
                        : [0.0, 0.0, 1.0]];
                }

                return Http::response(['data' => $data], 200);
            },
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'XX-ACK']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $answer = app(ChatbotRunner::class)->answer($bot, $message);

        $this->assertSame('XX-ACK', $answer->displayBody);
        $this->assertSame('conversation', $answer->answerOrigin);
        $this->assertSame(0, $answer->tokensUsed);
        // Recognising the turn and phrasing the reply are both infrastructure.
        $this->assertDatabaseCount('ai_credit_usages', 0);
    }

    public function test_the_conversation_language_is_remembered_and_reused(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'status' => 'open',
        ]);
        $language = app(ConversationLanguage::class);

        $this->assertSame('und', $language->resolve($conversation->id));

        $language->remember($conversation->id, 'pt-BR');
        $this->assertSame('pt-BR', $language->resolve($conversation->id));

        // Prose is not a language tag and must never reach the column.
        $language->remember($conversation->id, 'the customer is writing in Portuguese');
        $this->assertSame('pt-BR', $language->resolve($conversation->id));
    }
}
