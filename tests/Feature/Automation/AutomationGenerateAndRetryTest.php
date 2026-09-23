<?php

namespace Tests\Feature\Automation;

use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use App\Modules\Automation\Services\WorkflowGenerator;
use App\Modules\Automation\Services\WorkflowValidator;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The two capabilities ported from Wisperbot — drafting an automation from a
 * description, and retrying a failed run — adapted to Cerqle's hardened engine.
 */
class AutomationGenerateAndRetryTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->ctx = $this->createSubscribedWorkspaceContext();
        $this->account = ChannelAccount::create(['workspace_id' => $this->ctx['workspace']->id, 'channel' => 'whatsapp', 'provider' => 'meta', 'status' => 'active', 'display_name' => 'Support', 'business_account_id' => 'w', 'phone_number_id' => 'p']);
        $this->actingAs($this->ctx['user']);
    }

    private function automation(string $status = 'active'): Automation
    {
        return Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Support flow',
            'status' => $status,
            'trigger_type' => 'message.received',
            'trigger_config' => ['channel_account_id' => $this->account->id],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger', 'data' => []],
                ['id' => 'send', 'type' => 'send_whatsapp', 'data' => ['body' => 'Hello']],
            ],
            'edges' => [['source' => 't', 'target' => 'send']],
        ]);
    }

    /** A run that failed at the 'send' step, optionally with a recorded outcome. */
    private function failedRun(Automation $automation, ?string $loggedError, string $runError = 'Provider rejected the message'): AutomationRun
    {
        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'status' => 'failed',
            'current_node_id' => 'send',
            'error' => $runError,
            'completed_at' => now(),
        ]);
        DB::table('automation_step_claims')->insert(['run_id' => $run->id, 'node_id' => 'send', 'created_at' => now(), 'updated_at' => now()]);
        if ($loggedError !== null) {
            AutomationRunLog::create(['run_id' => $run->id, 'node_id' => 'send', 'node_type' => 'send_whatsapp', 'result' => 'error', 'message' => $loggedError]);
        }

        return $run;
    }

    private function retry(Automation $automation, AutomationRun $run, bool $confirmed = false)
    {
        return $this->from(route('client.automations.runs', $automation->uuid))
            ->post(route('client.automations.runs.retry', [$automation->uuid, $run->id]), ['confirmed' => $confirmed]);
    }

    // ── Retry ───────────────────────────────────────────────────────────────

    public function test_a_definite_failure_retries_from_the_failed_step(): void
    {
        // The step reported an error and finished, so nothing was delivered.
        $automation = $this->automation();
        $run = $this->failedRun($automation, 'Template is not approved.');

        $this->retry($automation, $run)->assertSessionHas('success');

        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertSame('send', $run->resume_node_id, 'It must resume at the step that failed, not start over.');
        $this->assertNull($run->error);
        // Without releasing the claim, the engine would refuse the step again.
        $this->assertDatabaseMissing('automation_step_claims', ['run_id' => $run->id, 'node_id' => 'send']);
        Queue::assertPushed(ExecuteAutomationRunJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_a_step_that_may_have_delivered_is_not_retried_without_confirmation(): void
    {
        // No recorded outcome: the step crashed mid-send and may have reached
        // the customer. Retrying blindly could send the message twice.
        $automation = $this->automation();
        $run = $this->failedRun($automation, null, 'Step already attempted; review delivery before retrying.');

        $this->retry($automation, $run)->assertSessionHas('error');

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseHas('automation_step_claims', ['run_id' => $run->id, 'node_id' => 'send']);
        Queue::assertNothingPushed();
    }

    public function test_an_operator_can_retry_after_confirming_they_checked_the_chat(): void
    {
        $automation = $this->automation();
        $run = $this->failedRun($automation, null, 'Provider returned no message ID; delivery requires review.');

        $this->retry($automation, $run, confirmed: true)->assertSessionHas('success');

        $this->assertSame('pending', $run->fresh()->status);
        Queue::assertPushed(ExecuteAutomationRunJob::class);
    }

    public function test_an_error_that_asks_for_review_counts_as_possibly_delivered(): void
    {
        // A logged error is not enough on its own: this one says delivery is
        // uncertain, so it must still ask first.
        $automation = $this->automation();
        $run = $this->failedRun($automation, 'Provider returned no message ID; delivery requires review.');

        $this->retry($automation, $run)->assertSessionHas('error');
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_a_paused_automation_cannot_retry_its_runs(): void
    {
        // The job cancels runs of a paused automation, so a retry would only
        // appear to work and then silently do nothing.
        $automation = $this->automation('paused');
        $run = $this->failedRun($automation, 'Template is not approved.');

        $this->retry($automation, $run)->assertSessionHas('error');
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_only_failed_runs_can_be_retried(): void
    {
        $automation = $this->automation();
        $run = $this->failedRun($automation, 'x');
        $run->update(['status' => 'completed']);

        $this->retry($automation, $run)->assertSessionHas('error');
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_a_run_from_another_automation_cannot_be_retried_through_this_one(): void
    {
        $automation = $this->automation();
        $other = $this->automation();
        $run = $this->failedRun($other, 'Template is not approved.');

        $this->retry($automation, $run)->assertNotFound();
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_the_runs_page_says_whether_each_failed_run_can_be_retried(): void
    {
        $automation = $this->automation();
        $this->failedRun($automation, 'Template is not approved.');

        $this->get(route('client.automations.runs', $automation->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('runs.data.0.retry.retryable', true)
                ->where('runs.data.0.retry.needs_confirmation', false));
    }

    // ── Generate with AI ─────────────────────────────────────────────────────

    private function fakeGraph(): array
    {
        return [
            'name' => 'Pricing reply',
            'trigger_type' => 'message.received',
            'trigger_config' => [],
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'data' => ['triggerType' => 'message.received']],
                ['id' => 'n1', 'type' => 'send_whatsapp', 'data' => ['body' => 'Our plans start at $10']],
            ],
            'edges' => [['source' => 'trigger-1', 'target' => 'n1']],
        ];
    }

    public function test_generating_creates_a_draft_that_is_never_switched_on(): void
    {
        $generator = Mockery::mock(WorkflowGenerator::class);
        $generator->shouldReceive('generate')->once()->andReturn($this->fakeGraph());
        $this->app->instance(WorkflowGenerator::class, $generator);

        $response = $this->postJson(route('client.automations.generate'), ['prompt' => 'Reply to pricing questions']);

        $response->assertOk()->assertJson(['ok' => true]);
        $automation = Automation::where('workspace_id', $this->ctx['workspace']->id)->where('name', 'Pricing reply')->sole();
        // A person reviews it first: the AI cannot know which templates are
        // approved, and an active draft would start replying to customers.
        $this->assertSame('draft', $automation->status);
        $response->assertJsonPath('redirect', route('client.automations.edit', $automation->uuid));
    }

    public function test_a_single_whatsapp_number_is_chosen_for_the_draft(): void
    {
        $generator = Mockery::mock(WorkflowGenerator::class);
        $generator->shouldReceive('generate')->andReturn($this->fakeGraph());
        $this->app->instance(WorkflowGenerator::class, $generator);

        $this->postJson(route('client.automations.generate'), ['prompt' => 'x'])->assertOk();

        $automation = Automation::where('name', 'Pricing reply')->sole();
        $this->assertSame($this->account->id, $automation->trigger_config['channel_account_id'] ?? null);
    }

    public function test_with_several_numbers_the_choice_is_left_to_the_person(): void
    {
        ChannelAccount::create(['workspace_id' => $this->ctx['workspace']->id, 'channel' => 'whatsapp', 'provider' => 'meta', 'status' => 'active', 'display_name' => 'Sales', 'business_account_id' => 'w2', 'phone_number_id' => 'p2']);
        $generator = Mockery::mock(WorkflowGenerator::class);
        $generator->shouldReceive('generate')->andReturn($this->fakeGraph());
        $this->app->instance(WorkflowGenerator::class, $generator);

        $this->postJson(route('client.automations.generate'), ['prompt' => 'x'])->assertOk();

        $this->assertArrayNotHasKey('channel_account_id', Automation::where('name', 'Pricing reply')->sole()->trigger_config ?? []);
    }

    public function test_generating_inside_the_builder_returns_the_draft_without_saving_it(): void
    {
        // The builder puts the draft on the canvas; only Save may write it,
        // so an existing automation is never replaced behind the person's back.
        $before = Automation::where('workspace_id', $this->ctx['workspace']->id)->count();
        $generator = Mockery::mock(WorkflowGenerator::class);
        $generator->shouldReceive('generate')->once()->andReturn($this->fakeGraph());
        $this->app->instance(WorkflowGenerator::class, $generator);

        $this->postJson(route('client.automations.generate'), ['prompt' => 'Reply to pricing questions', 'persist' => false])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('graph.nodes.1.type', 'send_whatsapp')
            ->assertJsonPath('graph.trigger_config.channel_account_id', $this->account->id)
            ->assertJsonMissingPath('redirect');

        $this->assertSame($before, Automation::where('workspace_id', $this->ctx['workspace']->id)->count());
    }

    public function test_the_builder_shows_what_generating_costs(): void
    {
        $automation = Automation::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'Existing', 'status' => 'draft', 'trigger_type' => 'message.received', 'nodes' => [], 'edges' => []]);

        $this->get(route('client.automations.edit', $automation->uuid))
            ->assertInertia(fn ($page) => $page->component('Automation/Builder')->where('generateCost', (int) config('ai.credits.rates.automation_workflow_generate')));
    }

    public function test_running_out_of_credits_is_reported_plainly(): void
    {
        $generator = Mockery::mock(WorkflowGenerator::class);
        $generator->shouldReceive('generate')->andThrow(new AiCreditsExhaustedException);
        $this->app->instance(WorkflowGenerator::class, $generator);

        $this->postJson(route('client.automations.generate'), ['prompt' => 'x'])
            ->assertStatus(402)
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['error' => 'Cerqle AI credits are exhausted. Add your API provider or upgrade your plan.']);

        $this->assertSame(0, Automation::where('workspace_id', $this->ctx['workspace']->id)->count());
    }

    public function test_the_generator_only_keeps_actions_the_server_will_activate(): void
    {
        // A model asked for "welcome new contacts" happily invents email, AI
        // replies and a contact-created trigger. None of those can be
        // switched on in Cerqle, so they must not reach the draft.
        $reply = json_encode([
            'name' => 'Welcome',
            'trigger_type' => 'contact.created',
            'nodes' => [
                ['id' => 'a', 'type' => 'send_whatsapp', 'data' => ['body' => 'Hi']],
                ['id' => 'b', 'type' => 'send_email', 'data' => ['subject' => 's', 'body' => 'b']],
                ['id' => 'c', 'type' => 'ai_reply', 'data' => ['prompt' => 'p']],
                ['id' => 'd', 'type' => 'add_tag', 'data' => ['tag' => 'new']],
            ],
            'edges' => [['source' => 'trigger-1', 'target' => 'a'], ['source' => 'a', 'target' => 'b'], ['source' => 'b', 'target' => 'c'], ['source' => 'c', 'target' => 'd']],
        ]);
        $gateway = Mockery::mock(LlmGateway::class);
        $gateway->shouldReceive('chat')->andReturn(new LlmResponse($reply, 10, 10, 'test', 1));
        $this->app->instance(LlmGateway::class, $gateway);

        $graph = app(WorkflowGenerator::class)->generate($this->ctx['workspace']->id, 'Welcome new contacts');

        $this->assertSame('message.received', $graph['trigger_type']);
        $types = collect($graph['nodes'])->pluck('type')->reject(fn ($t) => $t === 'trigger')->values()->all();
        $this->assertSame(['send_whatsapp', 'add_tag'], $types);
        foreach ($types as $type) {
            $this->assertContains($type, WorkflowValidator::TYPES);
        }
    }

    public function test_the_builder_palette_and_the_validator_agree(): void
    {
        // The builder's palette lives in JavaScript and the rule that decides
        // activation lives in PHP. If they drift, clients can add a step they
        // are then refused permission to switch on, or lose one they could use.
        $source = file_get_contents(resource_path('js/Utils/automationRelease.js'));
        preg_match('/automationReleaseNodes\s*=\s*\[(.*?)\]/s', $source, $match);
        preg_match_all("/'([a-z_]+)'/", $match[1] ?? '', $types);

        $this->assertEqualsCanonicalizing(WorkflowValidator::TYPES, $types[1]);
    }
}
