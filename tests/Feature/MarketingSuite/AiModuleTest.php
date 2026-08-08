<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiModuleTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        return [$user, $workspace];
    }

    #[Test]
    public function creating_knowledge_base_succeeds(): void
    {
        [$user] = $this->createUserWithWorkspace();

        $response = $this->actingAs($user)->post('/app/ai/knowledge-bases', [
            'name' => 'Product FAQ',
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseHas('ai_knowledge_bases', ['name' => 'Product FAQ', 'workspace_id' => $user->workspace_id]);
    }

    #[Test]
    public function client_can_rename_own_knowledge_base(): void
    {
        [$user, $workspace] = $this->createUserWithWorkspace();
        $kb = AiKnowledgeBase::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($user)->put("/app/ai/knowledge-bases/{$kb->uuid}", [
            'name' => 'Updated Help Center',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ai_knowledge_bases', [
            'id' => $kb->id,
            'name' => 'Updated Help Center',
        ]);
    }

    #[Test]
    public function deleting_knowledge_base_cleans_documents_files_vectors_and_chatbot_link(): void
    {
        Storage::fake('public');

        [$user, $workspace] = $this->createUserWithWorkspace();
        $kb = AiKnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'file',
            'source_ref' => 'kb-docs/guide.txt',
            'title' => 'Guide',
            'status' => 'indexed',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => 'Indexed knowledge base content.',
            'tokens' => 8,
        ]);
        $chatbot = AiChatbot::factory()->create([
            'workspace_id' => $workspace->id,
            'ai_kb_id' => $kb->id,
        ]);
        Storage::disk('public')->put('kb-docs/guide.txt', 'Uploaded knowledge base content.');

        $response = $this->actingAs($user)->delete("/app/ai/knowledge-bases/{$kb->uuid}");

        $response->assertRedirect('/app/ai/knowledge-bases');
        $this->assertDatabaseMissing('ai_knowledge_bases', ['id' => $kb->id]);
        $this->assertDatabaseMissing('ai_kb_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('ai_kb_chunks', ['id' => $chunk->id]);
        $this->assertDatabaseHas('ai_chatbots', ['id' => $chatbot->id, 'ai_kb_id' => null]);
        Storage::disk('public')->assertMissing('kb-docs/guide.txt');
    }

    #[Test]
    public function adding_document_dispatches_index_job(): void
    {
        Queue::fake();

        [$user, $workspace] = $this->createUserWithWorkspace();

        // Create the KB via the API so it's visible to subsequent requests
        $this->actingAs($user)->post('/app/ai/knowledge-bases', ['name' => 'Doc Test KB']);

        $kb = AiKnowledgeBase::where('name', 'Doc Test KB')->first();
        $this->assertNotNull($kb, 'KB should have been created via API');

        $response = $this->actingAs($user)->post("/app/ai/knowledge-bases/{$kb->uuid}/documents", [
            'source_type' => 'text',
            'source_ref' => 'This is a test document with some content about our product.',
            'title' => 'Test Document',
        ]);

        $response->assertStatus(302);
        Queue::assertPushed(IndexDocumentJob::class);
    }

    #[Test]
    public function sitemap_and_faq_sources_can_no_longer_be_added(): void
    {
        [$user, $workspace] = $this->createUserWithWorkspace();
        $kb = AiKnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);

        foreach (['sitemap' => 'https://example.com/sitemap.xml', 'faq' => 'Question and answer'] as $sourceType => $sourceRef) {
            $this->actingAs($user)
                ->from("/app/ai/knowledge-bases/{$kb->uuid}")
                ->post("/app/ai/knowledge-bases/{$kb->uuid}/documents", [
                    'source_type' => $sourceType,
                    'source_ref' => $sourceRef,
                ])
                ->assertRedirect("/app/ai/knowledge-bases/{$kb->uuid}")
                ->assertSessionHasErrors('source_type');
        }

        $this->assertDatabaseCount('ai_kb_documents', 0);
    }

    #[Test]
    public function file_source_requires_an_uploaded_file(): void
    {
        [$user, $workspace] = $this->createUserWithWorkspace();
        $kb = AiKnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user)
            ->from("/app/ai/knowledge-bases/{$kb->uuid}")
            ->post("/app/ai/knowledge-bases/{$kb->uuid}/documents", [
                'source_type' => 'file',
            ])
            ->assertRedirect("/app/ai/knowledge-bases/{$kb->uuid}")
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('ai_kb_documents', 0);
    }

    #[Test]
    public function creating_chatbot_stores_in_database(): void
    {
        [$user] = $this->createUserWithWorkspace();

        $response = $this->actingAs($user)->post('/app/ai/chatbots', [
            'name' => 'Support Bot',
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseHas('ai_chatbots', ['name' => 'Support Bot', 'workspace_id' => $user->workspace_id]);
    }

    #[Test]
    public function workspace_scoping_prevents_cross_workspace_kb_access(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        // UserB creates a KB via API
        $this->actingAs($userB)->post('/app/ai/knowledge-bases', ['name' => 'WS-B KB']);
        $kb = AiKnowledgeBase::where('name', 'WS-B KB')->first();
        $this->assertNotNull($kb, 'KB for workspace B should exist');

        // UserA tries to add a document to UserB's KB — should be forbidden
        $response = $this->actingAs($userA)->post("/app/ai/knowledge-bases/{$kb->uuid}/documents", [
            'source_type' => 'text',
            'source_ref' => 'Attempting cross-workspace injection.',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function workspace_scoping_prevents_renaming_or_deleting_another_clients_knowledge_base(): void
    {
        [$userA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();
        $kb = AiKnowledgeBase::factory()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Protected KB',
        ]);

        $this->actingAs($userA)
            ->put("/app/ai/knowledge-bases/{$kb->uuid}", ['name' => 'Unauthorized Rename'])
            ->assertForbidden();

        $this->actingAs($userA)
            ->delete("/app/ai/knowledge-bases/{$kb->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('ai_knowledge_bases', [
            'id' => $kb->id,
            'name' => 'Protected KB',
        ]);
    }

    #[Test]
    public function client_can_test_a_saved_ai_provider_connection(): void
    {
        [$user, $workspace] = $this->createUserWithWorkspace();
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1],
                'model' => 'gpt-4o-mini',
            ]),
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson('/app/ai/providers/openai/test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('capabilities.chat', true)
            ->assertJsonPath('capabilities.embeddings', true);
    }

    #[Test]
    public function playground_shows_safe_provider_error_without_exposing_upstream_body(): void
    {
        [$user, $workspace] = $this->createUserWithWorkspace();
        $chatbot = AiChatbot::factory()->create([
            'workspace_id' => $workspace->id,
            'enabled' => true,
            'ai_kb_id' => null,
        ]);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-invalid'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'error' => [
                    'code' => 'invalid_api_key',
                    'message' => 'secret-provider-body',
                ],
            ], 401),
        ]);

        $response = $this->actingAs($user)->postJson("/app/ai/chatbots/{$chatbot->uuid}/playground", [
            'message' => 'Hello',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'provider_authentication_failed')
            ->assertJsonPath('error', 'The AI provider rejected the credentials. Check the API key and provider access, then test again.');
        $this->assertStringNotContainsString('secret-provider-body', $response->getContent());
    }

    #[Test]
    public function indexing_failure_is_stored_as_a_safe_visible_document_error(): void
    {
        [, $workspace] = $this->createUserWithWorkspace();
        $kb = AiKnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'text',
            'source_ref' => 'Product support information.',
            'title' => 'Support guide',
            'status' => 'pending',
        ]);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-invalid'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'error' => [
                    'code' => 'invalid_api_key',
                    'message' => 'secret-indexing-provider-body',
                ],
            ], 401),
        ]);

        try {
            $this->app->call([new IndexDocumentJob($document->id), 'handle']);
            $this->fail('Indexing should throw when the embedding provider rejects the credentials.');
        } catch (\Throwable) {
            // The queue retries the original error; the document keeps a safe client-facing reason.
        }

        $document->refresh();
        $this->assertSame('error', $document->status);
        $this->assertSame(
            'The AI provider rejected the credentials. Check the API key and provider access, then test again.',
            $document->error_message,
        );
        $this->assertStringNotContainsString('secret-indexing-provider-body', $document->error_message);
    }
}
