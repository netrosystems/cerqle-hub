<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a Smart Bot and giving it something to answer from used to be two
 * separate journeys, and a client who started with the bot met an empty
 * knowledge dropdown with no explanation. These pin the combined flow.
 */
class BotSingleFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    public function test_creating_a_bot_creates_the_knowledge_it_answers_from(): void
    {
        $response = $this->actingAs($this->ctx['user'])->post(route('client.ai.chatbots.store'), [
            'name' => 'Support concierge',
            'business_name' => 'Cerqle',
            'business_purpose' => 'AI automation for support teams',
            'target_audience' => 'Small support teams',
        ]);

        $bot = AiChatbot::where('workspace_id', $this->ctx['workspace']->id)->firstOrFail();
        $response->assertRedirect(route('client.ai.chatbots.show', $bot->uuid));

        $this->assertNotNull($bot->ai_kb_id, 'the bot must arrive with knowledge attached');
        $kb = AiKnowledgeBase::findOrFail($bot->ai_kb_id);
        $this->assertSame('Cerqle', $kb->business_name);
        $this->assertSame('AI automation for support teams', $kb->business_purpose);
        $this->assertSame('Small support teams', $kb->target_audience);
        $this->assertSame((int) $this->ctx['workspace']->id, (int) $kb->workspace_id);
    }

    public function test_a_bot_can_reuse_knowledge_that_already_exists(): void
    {
        $existing = AiKnowledgeBase::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Shared',
            'business_name' => 'Cerqle',
        ]);

        $this->actingAs($this->ctx['user'])->post(route('client.ai.chatbots.store'), [
            'name' => 'Second bot',
            'ai_kb_id' => $existing->id,
        ]);

        $bot = AiChatbot::where('name', 'Second bot')->firstOrFail();
        $this->assertSame((int) $existing->id, (int) $bot->ai_kb_id);
        $this->assertSame(1, AiKnowledgeBase::count(), 'reusing knowledge must not create a second one');
    }

    public function test_knowledge_from_another_workspace_is_ignored_rather_than_attached(): void
    {
        $other = $this->createWorkspaceContext();
        $foreign = AiKnowledgeBase::create(['workspace_id' => $other['workspace']->id, 'name' => 'Theirs']);

        $this->actingAs($this->ctx['user'])->post(route('client.ai.chatbots.store'), [
            'name' => 'Mine',
            'ai_kb_id' => $foreign->id,
        ]);

        $bot = AiChatbot::where('name', 'Mine')->firstOrFail();
        $this->assertNotSame((int) $foreign->id, (int) $bot->ai_kb_id);
        $this->assertSame((int) $this->ctx['workspace']->id, (int) AiKnowledgeBase::findOrFail($bot->ai_kb_id)->workspace_id);
    }

    public function test_the_bot_page_reports_what_is_still_missing(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.ai.chatbots.store'), ['name' => 'Bare bot']);
        $bot = AiChatbot::where('name', 'Bare bot')->firstOrFail();

        $response = $this->actingAs($this->ctx['user'])->get(route('client.ai.chatbots.show', $bot->uuid));

        $response->assertOk();
        $readiness = collect($response->viewData('page')['props']['readiness'])->keyBy('key');
        $this->assertFalse($readiness['knowledge']['ok'], 'a bot with no sources is not ready');
        $this->assertFalse($readiness['profile']['ok'], 'no business details were given');
        $this->assertStringContainsString('only answers from your documents', $readiness['profile']['detail']);
    }

    public function test_another_workspace_cannot_open_the_bot(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.ai.chatbots.store'), ['name' => 'Private']);
        $bot = AiChatbot::where('name', 'Private')->firstOrFail();

        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])
            ->get(route('client.ai.chatbots.show', $bot->uuid))
            ->assertForbidden();
    }
}
