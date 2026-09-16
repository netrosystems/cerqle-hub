<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbGeneration;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\WidgetPayloadBuilder;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroundedAiAnsweringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.smart_bot.business_aware_routing', true);
        config()->set('ai.smart_bot.hybrid_retrieval', true);
    }

    public function test_greeting_is_deterministic_and_uses_no_customer_credit(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id, 'answer_scope' => 'business_only']);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Hello!', $workspace->id);

        $this->assertSame('greeting', $result['answer_origin']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertDatabaseCount('ai_credit_usages', 0);
    }

    public function test_account_specific_request_is_never_injected_into_ai_context(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id, 'answer_scope' => 'general']);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Where is my order #123?', $workspace->id);

        $this->assertSame('handoff', $result['response_mode']);
        $this->assertTrue($result['handoff_offer']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertDatabaseCount('ai_credit_usages', 0);
    }

    public function test_incomplete_business_profile_safely_downgrades_and_only_clarifies_once(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = AiKnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);
        $bot = AiChatbot::factory()->create([
            'workspace_id' => $workspace->id,
            'ai_kb_id' => $kb->id,
            'answer_scope' => 'business_only',
            'fallback_mode' => 'clarify_then_handoff',
        ]);

        $first = app(ChatbotRunner::class)->runForApi($bot, 'Tell me about something unrelated', $workspace->id);
        $second = app(ChatbotRunner::class)->runForApi($bot, 'Still unrelated', $workspace->id, [
            ['role' => 'assistant', 'content' => 'Could you clarify what you need?'],
        ]);

        $this->assertSame('clarification', $first['response_mode']);
        $this->assertSame('handoff', $second['response_mode']);
    }

    public function test_exact_legacy_faq_answer_is_grounded_and_zero_credit(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = AiKnowledgeBase::factory()->create([
            'workspace_id' => $workspace->id,
            'business_name' => 'Cerqle',
            'business_purpose' => 'Customer communication software',
            'target_audience' => 'Support teams',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'faq',
            'source_ref' => 'legacy',
            'title' => 'Refund FAQ',
            'status' => 'indexed',
        ]);
        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => "Q: What is your refund policy?\nA: Refunds are available within 30 days.",
            'tokens' => 15,
            'source_title' => 'Refund FAQ',
        ]);
        $bot = AiChatbot::factory()->create([
            'workspace_id' => $workspace->id,
            'ai_kb_id' => $kb->id,
            'answer_scope' => 'verified_only',
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'What is your refund policy?', $workspace->id);

        $this->assertSame('Refunds are available within 30 days.', $result['reply']);
        $this->assertSame('knowledge_base', $result['answer_origin']);
        $this->assertSame('Refund FAQ', $result['citations'][0]['title']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertDatabaseCount('ai_credit_usages', 0);
    }

    public function test_failed_reindex_keeps_the_active_generation_untouched(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = AiKnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);
        $generation = AiKbGeneration::create(['kb_id' => $kb->id, 'status' => 'active', 'activated_at' => now()]);
        $kb->update(['active_generation_id' => $generation->id]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'source_type' => 'text', 'source_ref' => 'New content',
            'title' => 'Guide', 'status' => 'indexed',
        ]);
        $oldChunk = AiKbChunk::create([
            'kb_id' => $kb->id, 'generation_id' => $generation->id, 'document_id' => $document->id,
            'ord' => 0, 'content' => 'Published old content', 'tokens' => 4,
        ]);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id, 'provider' => 'openai', 'credentials' => ['api_key' => 'bad'],
            'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small', 'enabled' => true,
        ]);
        Http::fake(['api.openai.com/v1/embeddings' => Http::response(['error' => ['message' => 'denied']], 401)]);

        try {
            $this->app->call([new IndexDocumentJob($document->id), 'handle']);
            $this->fail('Expected indexing to fail.');
        } catch (\Throwable) {
        }

        $this->assertSame($generation->id, $kb->fresh()->active_generation_id);
        $this->assertNull($kb->fresh()->pending_generation_id);
        $this->assertDatabaseHas('ai_kb_chunks', ['id' => $oldChunk->id, 'content' => 'Published old content']);
        $this->assertDatabaseHas('ai_kb_generations', ['kb_id' => $kb->id, 'status' => 'failed']);
    }

    public function test_widget_payload_exposes_sanitized_answer_controls(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $message = Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => 'webchat',
            'type' => 'text', 'body' => 'Can you clarify?', 'status' => 'sent', 'sent_by' => 'bot',
            'payload' => ['ai_answer' => [
                'quick_replies' => ['Tell me more', 'Talk to a person'],
                'answer_origin' => 'fallback', 'response_mode' => 'clarification',
                'citations' => [], 'handoff_offer' => true,
            ]],
        ]);
        $widget = new ChatWidget(['workspace_id' => $workspace->id]);

        $payload = app(WidgetPayloadBuilder::class)->message($message, $widget);

        $this->assertSame(['Tell me more', 'Talk to a person'], $payload['quick_replies']);
        $this->assertSame('clarification', $payload['response_mode']);
        $this->assertTrue($payload['handoff_offer']);
        $this->assertArrayNotHasKey('confidence', $payload);
    }
}
