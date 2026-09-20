<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiAnswerDiagnostic;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiKnowledgeGap;
use App\Modules\AI\Services\ChatbotRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every turn is accounted for, including the ones that cost nothing.
 *
 * Without this, "the AI is bad" and "a flag is off" look identical from the
 * outside, which is exactly how a silent bot becomes a support escalation.
 */
class SmartBotDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.smart_bot.diagnostics', true);
        config()->set('ai.smart_bot.business_aware_routing', true);
    }

    public function test_a_zero_credit_turn_still_writes_one_diagnostics_row(): void
    {
        Http::fake();
        $workspace = $this->createWorkspaceContext()['workspace'];
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'enabled' => true,
        ]);

        app(ChatbotRunner::class)->runForApi($bot, 'Where is my order #1234?', (int) $workspace->id);

        $row = AiAnswerDiagnostic::firstOrFail();
        $this->assertSame((int) $workspace->id, (int) $row->workspace_id);
        $this->assertSame('handoff', $row->decision);
        $this->assertSame('handoff', $row->answer_origin);
        $this->assertSame('none', $row->credit_result);
        $this->assertDatabaseCount('ai_credit_usages', 0);
        Http::assertNothingSent();
    }

    public function test_diagnostics_are_scoped_to_the_acting_workspace(): void
    {
        Http::fake();
        $first = $this->createWorkspaceContext()['workspace'];
        $second = $this->createWorkspaceContext()['workspace'];
        $bot = AiChatbot::create(['workspace_id' => $first->id, 'name' => 'Bot', 'enabled' => true]);

        app(ChatbotRunner::class)->runForApi($bot, 'track my delivery', (int) $first->id);

        $this->assertSame(1, AiAnswerDiagnostic::where('workspace_id', $first->id)->count());
        $this->assertSame(0, AiAnswerDiagnostic::where('workspace_id', $second->id)->count());
    }

    public function test_an_unanswered_question_becomes_a_knowledge_gap_with_occurrence_counts(): void
    {
        Http::fake();
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Handbook',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'answer_scope' => 'verified_only',
            'fallback_mode' => 'handoff',
            'enabled' => true,
        ]);

        $runner = app(ChatbotRunner::class);
        $runner->runForApi($bot, 'Do you ship to the islands?', (int) $workspace->id);
        $runner->runForApi($bot, 'do you SHIP to the islands', (int) $workspace->id);

        $gap = AiKnowledgeGap::firstOrFail();
        $this->assertSame(1, AiKnowledgeGap::count(), 'The same question should collapse into one gap.');
        $this->assertSame(2, $gap->occurrences);
        $this->assertSame((int) $workspace->id, (int) $gap->workspace_id);
    }

    public function test_conversational_turns_are_not_recorded_as_knowledge_gaps(): void
    {
        // The legacy greeting shortcut, which the turn resolver supersedes.
        config()->set('ai.smart_bot.conversational_turns', false);
        Http::fake();
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Handbook',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'enabled' => true,
        ]);

        app(ChatbotRunner::class)->runForApi($bot, 'hello', (int) $workspace->id);

        $this->assertSame(0, AiKnowledgeGap::count());
        $this->assertSame('greeting', AiAnswerDiagnostic::firstOrFail()->answer_origin);
    }

    public function test_diagnostics_stay_off_until_the_flag_is_enabled(): void
    {
        Http::fake();
        config()->set('ai.smart_bot.diagnostics', false);
        $workspace = $this->createWorkspaceContext()['workspace'];
        $bot = AiChatbot::create(['workspace_id' => $workspace->id, 'name' => 'Bot', 'enabled' => true]);

        app(ChatbotRunner::class)->runForApi($bot, 'track my order', (int) $workspace->id);

        $this->assertSame(0, AiAnswerDiagnostic::count());
    }

    public function test_pruning_removes_rows_past_the_retention_window(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        AiAnswerDiagnostic::create(['workspace_id' => $workspace->id, 'decision' => 'answer', 'created_at' => now()->subDays(120), 'updated_at' => now()->subDays(120)]);
        AiAnswerDiagnostic::create(['workspace_id' => $workspace->id, 'decision' => 'answer']);

        $this->artisan('ai:prune-diagnostics')->assertSuccessful();

        $this->assertSame(1, AiAnswerDiagnostic::count());
    }
}
