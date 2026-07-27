<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ChatbotRunner end-to-end test with Http::fake for OpenAI.
 * Verifies that context chunks from the KB are included in the prompt.
 */
class ChatbotRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_runner_includes_kb_context_in_prompt(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        // Seed: KB + document + chunk with a known embedding
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'FAQ',
            'source_type' => 'text',
            'source_ref' => 'Our refund policy is 30 days.',
            'status' => 'indexed',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Our refund policy is 30 days.',
            'tokens' => 8,
            'embedding' => null,
        ]);

        // Manually store a small embedding that matches anything
        $embeddingStore = app(EmbeddingStore::class);
        $embeddingStore->storeEmbedding($chunk, [0.1, 0.2, 0.3]);

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'system_prompt' => 'You are a helpful assistant.',
            'max_context_chunks' => 3,
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conv = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = new Message;
        $message->body = 'What is your refund policy?';
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', $conv);

        $capturedSystemPrompt = null;
        $capturedRequest = null;

        // Fake both embedding and chat OpenAI calls using URL-keyed closures
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use (&$capturedSystemPrompt, &$capturedRequest) {
                $body = json_decode($request->body(), true);
                $capturedRequest = $body;
                $capturedSystemPrompt = collect($body['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';

                return Http::response([
                    'choices' => [['message' => ['content' => 'Our refund policy is 30 days.']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        // Add LLM workspace credential via AiProviderConfig
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        $runner = app(ChatbotRunner::class);
        $reply = $runner->run($chatbot, $message);

        $this->assertNotNull($reply, 'ChatbotRunner should return a reply');
        $this->assertStringContainsString('refund', strtolower($reply));
        // Assert that context chunks were included in the system prompt sent to OpenAI
        $this->assertNotNull($capturedSystemPrompt, 'System prompt should have been captured');
        $this->assertStringContainsString('refund policy is 30 days', $capturedSystemPrompt);
        $this->assertStringContainsString('reply in that language', $capturedSystemPrompt);
        $this->assertStringContainsString('Never replace a concrete answer with a generic greeting', $capturedSystemPrompt);
        $this->assertSame(240, $capturedRequest['max_tokens']);
        $this->assertSame(0.45, $capturedRequest['temperature']);
    }

    public function test_chatbot_runner_uses_only_the_latest_twenty_messages_in_chronological_order(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Human Support Bot',
            'system_prompt' => 'Represent the Example brand.',
            'tone' => 'friendly',
            'enabled' => true,
            'channels' => ['website'],
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        foreach (range(1, 25) as $number) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'website',
                'type' => 'text',
                'body' => 'history-'.$number,
                'sent_at' => now()->addSeconds($number),
            ]);
        }

        $current = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'website',
            'type' => 'text',
            'body' => 'current question',
            'sent_at' => now()->addMinute(),
        ]);

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        $capturedMessages = [];
        Http::fake([
            'api.openai.com/v1/chat/completions' => function ($request) use (&$capturedMessages) {
                $capturedMessages = json_decode($request->body(), true)['messages'] ?? [];

                return Http::response([
                    'choices' => [['message' => ['content' => 'Short answer.']]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 3],
                    'model' => 'gpt-4o-mini',
                ]);
            },
        ]);

        app(ChatbotRunner::class)->run($chatbot, $current);

        $history = collect($capturedMessages)->where('role', 'user')->pluck('content')->values();
        $this->assertCount(21, $history);
        $this->assertSame('history-6', $history->first());
        $this->assertSame('history-25', $history->get(19));
        $this->assertSame('current question', $history->last());
        $this->assertNotContains('history-1', $history);
    }
}
