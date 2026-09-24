<?php

namespace Tests\Feature\Automation;

use App\Events\MessageReceived;
use App\Listeners\AutomationTriggerListener;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Automation\Services\WorkflowValidator;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Services\ClientAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class AutomationReleaseSafetyTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $account;

    private Conversation $chat;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->ctx = $this->createSubscribedWorkspaceContext();
        $wid = $this->ctx['workspace']->id;
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $wid, 'waba_id' => 'qa-waba', 'credentials' => ['system_user_token' => 'test-only']]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'qa-phone', 'display_phone' => '+15550000001']);
        $this->account = ChannelAccount::create(['workspace_id' => $wid, 'channel' => 'whatsapp', 'provider' => 'meta', 'status' => 'active', 'display_name' => 'QA', 'business_account_id' => 'qa-waba', 'phone_number_id' => 'qa-phone']);
        $this->contact = Contact::factory()->create(['workspace_id' => $wid, 'opt_in_whatsapp' => true]);
        $this->chat = Conversation::create(['workspace_id' => $wid, 'channel_account_id' => $this->account->id, 'contact_id' => $this->contact->id, 'external_thread_id' => 'qa-chat', 'status' => 'open']);
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn('qa-provider-id');
        $this->app->instance(WhatsappDriver::class, $driver);
    }

    private function message(string $body = 'Hi', ?Conversation $chat = null): Message
    {
        return Message::create(['conversation_id' => ($chat ?? $this->chat)->id, 'direction' => 'in', 'channel' => 'whatsapp', 'type' => 'text', 'body' => $body, 'status' => 'received', 'sent_at' => now()]);
    }

    private function workflow(string $type = 'ask_question', array $data = []): Automation
    {
        return Automation::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'QA', 'status' => 'active', 'trigger_type' => 'message.received', 'trigger_config' => ['channel_account_id' => $this->account->id], 'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'data' => []],
            ['id' => 'q', 'type' => $type, 'data' => $data ?: ['question' => 'Name?', 'variable' => 'answer']],
            ['id' => 'end', 'type' => 'send_whatsapp', 'data' => ['body' => 'Thanks {{context.answer}}']],
        ], 'edges' => [['source' => 't', 'target' => 'q'], ['source' => 'q', 'target' => 'end']]]);
    }

    private function trigger(Automation $auto, Message $message): AutomationRun
    {
        app(AutomationTriggerListener::class)->handleMessageReceived(new MessageReceived($message));

        return $auto->runs()->latest('id')->firstOrFail();
    }

    private function execute(AutomationRun $run): void
    {
        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));
    }

    public function test_reply_continues_without_restart_and_duplicate_delivery_is_safe(): void
    {
        $auto = $this->workflow();
        $message = $this->message();
        $run = $this->trigger($auto, $message);
        $this->trigger($auto, $message);
        $this->assertSame(1, $auto->runs()->count());
        $this->execute($run);
        $this->assertSame('waiting', $run->fresh()->status);
        $reply = $this->message('Alice');
        $this->trigger($auto, $reply);
        $this->trigger($auto, $reply);
        $this->assertSame(1, $auto->runs()->count());
        $this->execute($run->fresh());
        $this->execute($run->fresh());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, Message::where('direction', 'out')->count());
        $this->assertSame('Thanks Alice', Message::where('direction', 'out')->latest('id')->first()->body);
    }

    public function test_other_chat_and_media_only_reply_cannot_consume_question(): void
    {
        $auto = $this->workflow();
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $other = Conversation::create(['workspace_id' => $this->chat->workspace_id, 'contact_id' => $this->contact->id, 'channel_account_id' => $this->account->id, 'external_thread_id' => 'other', 'status' => 'open']);
        app(AutomationEngine::class)->resumeAwaitingReplies($this->chat->workspace_id, $this->contact->id, 'Wrong', $other->id, 999);
        $this->trigger($auto, $this->message(''));
        $this->assertTrue($run->fresh()->context['_awaiting_reply']);
        $this->assertSame(1, $auto->runs()->count());
    }

    public function test_snapshot_survives_edits_and_timeout_does_not_resend(): void
    {
        $auto = $this->workflow();
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $nodes = $auto->nodes;
        $nodes[2]['data']['body'] = 'Edited';
        $auto->update(['nodes' => $nodes]);
        $this->trigger($auto, $this->message('Alice'));
        $this->execute($run->fresh());
        $this->assertSame('Thanks Alice', Message::where('direction', 'out')->latest('id')->first()->body);
        $new = $this->trigger($auto, $this->message());
        $this->execute($new);
        $this->travel(25)->hours();
        $this->execute($new->fresh());
        $this->assertSame('cancelled', $new->fresh()->status);
        $this->assertSame(3, Message::where('direction', 'out')->count());
    }

    public function test_delay_wakeup_and_paused_or_human_chat_stop_safely(): void
    {
        $auto = $this->workflow('wait', ['amount' => 1, 'unit' => 'minutes']);
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $this->execute($run->fresh());
        $this->assertSame('waiting', $run->fresh()->status);
        $this->travel(2)->minutes();
        $this->execute($run->fresh());
        $this->assertSame('completed', $run->fresh()->status);
        $new = $this->trigger($auto, $this->message());
        $this->execute($new);
        $this->chat->update(['assigned_to' => 'human']);
        $this->travel(2)->minutes();
        $this->execute($new->fresh());
        $this->assertSame('cancelled', $new->fresh()->status);
        $this->assertSame(1, Message::where('direction', 'out')->count());
    }

    public function test_closed_window_requires_template_and_changed_consent_blocks_send(): void
    {
        $auto = $this->workflow('wait', ['amount' => 2, 'unit' => 'days']);
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $this->travel(3)->days();
        $this->execute($run->fresh());
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, Message::where('direction', 'out')->count());
        $this->contact->update(['opt_in_whatsapp' => false]);
        $auto2 = $this->workflow('send_whatsapp', ['body' => 'Hello']);
        $new = $this->trigger($auto2, $this->message());
        $this->execute($new);
        $this->assertSame('failed', $new->fresh()->status);
    }

    public function test_invalid_graphs_and_empty_nodes_fail_preview_validation(): void
    {
        $validator = app(WorkflowValidator::class);
        $auto = $this->workflow();
        $this->assertSame([], $validator->errors($auto->nodes, $auto->edges));
        $this->assertNotEmpty($validator->errors($auto->nodes, [['source' => 't', 'target' => 'q']]));
        $this->assertNotEmpty($validator->errors($auto->nodes, array_merge($auto->edges, [['source' => 'end', 'target' => 'q']])));
        $this->assertNotEmpty($validator->errors($auto->nodes, [['source' => 't', 'target' => 'missing']]));
        $empty = $auto->nodes;
        $empty[1]['data']['question'] = '';
        $res = app(AutomationEngine::class)->testRun($auto, $empty, $auto->edges);
        $this->assertFalse($res['ok']);
        $this->assertSame(0, Message::count());
    }

    public function test_template_selection_rejects_wrong_waba_and_missing_variables(): void
    {
        $auto = $this->workflow('send_template', ['template_name' => 'qa', 'language' => 'en', 'variables' => ['Alice']]);
        WhatsappTemplate::create(['workspace_id' => $this->chat->workspace_id, 'waba_id' => 'other-waba', 'name' => 'qa', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']]]);
        $this->expectException(ValidationException::class);
        app(WorkflowValidator::class)->validate($this->chat->workspace_id, $auto->toArray(), true);
    }

    public function test_menu_uses_stable_ids_and_invalid_choice_does_not_restart(): void
    {
        $auto = $this->workflow('quick_replies', ['body' => 'Choose', 'buttons' => ['Sales', 'Support']]);
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $menu = Message::where('direction', 'out')->first();
        $id = data_get($menu->payload, 'interactive.action.buttons.0.reply.id');
        $this->assertSame($run->id.':q:btn_1', $id);
        $this->trigger($auto, $this->message('invalid'));
        $this->assertSame('waiting', $run->fresh()->status);
        $reply = $this->message('Sales');
        $reply->update(['payload' => ['interactive' => ['button_reply' => ['id' => $id, 'title' => 'Sales']]]]);
        $this->trigger($auto, $reply->fresh());
        $this->execute($run->fresh());
        $this->assertSame('btn_1', $run->fresh()->context['choice_id']);
        $this->assertSame('Sales', $run->fresh()->context['choice']);
        $this->assertSame(1, $auto->runs()->count());
    }

    /** @return array<string, array{string, string, string}> */
    public static function typedMenuReplies(): array
    {
        return [
            'title in another case' => ['  support ', 'Support', 'btn_2'],
            'button number' => ['1', 'Sales', 'btn_1'],
        ];
    }

    /** @dataProvider typedMenuReplies */
    public function test_a_typed_reply_that_names_a_button_counts_as_tapping_it(string $typed, string $choice, string $choiceId): void
    {
        $auto = $this->workflow('quick_replies', ['body' => 'Choose', 'buttons' => ['Sales', 'Support']]);
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);

        $this->trigger($auto, $this->message($typed));
        $this->execute($run->fresh());

        $this->assertSame($choice, $run->fresh()->context['choice']);
        $this->assertSame($choiceId, $run->fresh()->context['choice_id']);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_approved_template_followup_works_after_window_and_revalidates_approval(): void
    {
        $tpl = WhatsappTemplate::create(['workspace_id' => $this->chat->workspace_id, 'waba_id' => 'qa-waba', 'name' => 'qa', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']]]);
        $auto = $this->workflow('wait', ['amount' => 2, 'unit' => 'days']);
        $nodes = $auto->nodes;
        $nodes[2] = ['id' => 'end', 'type' => 'send_template', 'data' => ['template_name' => 'qa', 'language' => 'en', 'variables' => ['Alice']]];
        $auto->update(['nodes' => $nodes]);
        app(WorkflowValidator::class)->validate($this->chat->workspace_id, $auto->toArray(), true);
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $this->travel(3)->days();
        $this->execute($run->fresh());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('template', Message::where('direction', 'out')->first()->type);
        $new = $this->trigger($auto, $this->message());
        $this->execute($new);
        $tpl->update(['status' => 'REJECTED']);
        $this->travel(3)->days();
        $this->execute($new->fresh());
        $this->assertSame('failed', $new->fresh()->status);
        $this->assertSame(1, Message::where('direction', 'out')->count());
    }

    public function test_handoff_ends_run_and_does_not_execute_downstream_legacy_steps(): void
    {
        $auto = $this->workflow('assign_agent', []);
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $this->assertSame('human', $this->chat->fresh()->assigned_to);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(0, Message::where('direction', 'out')->count());
    }

    public function test_disconnected_phone_never_falls_back_to_another_sender(): void
    {
        $auto = $this->workflow('send_whatsapp', ['body' => 'Hello']);
        $run = $this->trigger($auto, $this->message());
        WhatsappPhoneNumber::where('phone_number_id', 'qa-phone')->first()->update(['coexistence_meta' => ['disconnected_at' => now()->toIso8601String()]]);
        $this->execute($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, Message::where('direction', 'out')->count());
    }

    public function test_claimed_step_fails_closed_instead_of_resending(): void
    {
        $auto = $this->workflow('send_whatsapp', ['body' => 'Hello']);
        $run = $this->trigger($auto, $this->message());
        DB::table('automation_step_claims')->insert(['run_id' => $run->id, 'node_id' => 'q']);
        $this->execute($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, Message::where('direction', 'out')->count());
    }

    public function test_pause_or_chat_deletion_cancels_pending_delay(): void
    {
        $auto = $this->workflow('wait', ['amount' => 1, 'unit' => 'minutes']);
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $auto->update(['status' => 'paused']);
        $this->travel(2)->minutes();
        $this->execute($run->fresh());
        $this->assertSame('cancelled', $run->fresh()->status);
        $auto->update(['status' => 'active']);
        $new = $this->trigger($auto, $this->message());
        $this->execute($new);
        $this->chat->delete();
        $this->travel(2)->minutes();
        $this->execute($new->fresh());
        $this->assertSame('cancelled', $new->fresh()->status);
    }

    public function test_missing_template_variables_are_rejected(): void
    {
        $auto = $this->workflow('send_template', ['template_name' => 'qa', 'language' => 'en']);
        WhatsappTemplate::create(['workspace_id' => $this->chat->workspace_id, 'waba_id' => 'qa-waba', 'name' => 'qa', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']]]);
        $this->expectException(ValidationException::class);
        app(WorkflowValidator::class)->validate($this->chat->workspace_id, $auto->toArray(), true);
    }

    public function test_subscription_expiry_cancels_bound_work(): void
    {
        $auto = $this->workflow('send_whatsapp', ['body' => 'Hello']);
        $run = $this->trigger($auto, $this->message());
        $access = Mockery::mock(ClientAccessService::class);
        $access->shouldReceive('allowsWorkspaceWrite')->andReturn(false);
        $this->app->instance(ClientAccessService::class, $access);
        $this->execute($run);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, Message::where('direction', 'out')->count());
    }

    public function test_controller_activates_reviewed_canvas_and_preview_is_read_only(): void
    {
        $auto = $this->workflow('send_whatsapp', ['body' => 'Reviewed']);
        $auto->update(['status' => 'draft', 'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []]], 'edges' => []]);
        $payload = $this->workflow('send_whatsapp', ['body' => 'Reviewed'])->only(['nodes', 'edges', 'trigger_type', 'trigger_config']);
        $payload['status'] = 'active';
        $this->actingAs($this->ctx['user'])->putJson(route('client.automations.update', $auto), $payload)->assertRedirect();
        $this->assertSame('active', $auto->fresh()->status);
        $this->assertSame('Reviewed', $auto->fresh()->nodes[1]['data']['body']);
        Http::preventStrayRequests();
        $this->postJson(route('client.automations.test', $auto), $payload + ['sample_message' => 'Hi', 'sample_answer' => 'Sales'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, Message::count());
        $this->assertSame(0, $auto->runs()->count());
    }

    public function test_saving_and_previewing_keep_condition_branches_and_node_positions(): void
    {
        // Request validation only keeps keys that have a rule, so an edge's
        // Yes/No handle and a node's canvas position used to be dropped:
        // every condition then failed Preview and Activate, and saved steps
        // piled up in one spot on reload.
        $auto = $this->workflow();
        $payload = [
            'trigger_type' => 'message.received',
            'trigger_config' => ['channel_account_id' => $this->account->id],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 0], 'data' => []],
                ['id' => 'c', 'type' => 'condition', 'position' => ['x' => 250, 'y' => 150], 'data' => ['field' => 'message.body', 'operator' => 'contains', 'value' => 'price']],
                ['id' => 'yes', 'type' => 'send_whatsapp', 'position' => ['x' => 50, 'y' => 300], 'data' => ['body' => 'Our prices']],
                ['id' => 'no', 'type' => 'assign_agent', 'position' => ['x' => 450, 'y' => 300], 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'target' => 'c'],
                ['id' => 'e2', 'source' => 'c', 'target' => 'yes', 'sourceHandle' => 'true'],
                ['id' => 'e3', 'source' => 'c', 'target' => 'no', 'sourceHandle' => 'false'],
            ],
        ];

        $this->actingAs($this->ctx['user'])->postJson(route('client.automations.test', $auto), $payload + ['sample_message' => 'What is the price?'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->putJson(route('client.automations.update', $auto), $payload + ['status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $auto->fresh();
        $this->assertSame('active', $saved->status);
        $this->assertSame(['true', 'false'], array_values(array_filter(array_column($saved->edges, 'sourceHandle'))));
        $this->assertSame('e2', $saved->edges[1]['id']);
        $this->assertSame(['x' => 50, 'y' => 300], $saved->nodes[2]['position']);
    }

    public function test_controller_blocks_invalid_activation_but_allows_incomplete_draft_and_pause(): void
    {
        $auto = $this->workflow();
        $auto->update(['status' => 'draft']);
        $this->actingAs($this->ctx['user'])->putJson(route('client.automations.update', $auto), ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []]], 'edges' => []])->assertRedirect();
        $this->putJson(route('client.automations.update', $auto), ['status' => 'active'])->assertUnprocessable();
        $this->putJson(route('client.automations.update', $auto), ['status' => 'paused'])->assertRedirect();
        $this->assertSame('paused', $auto->fresh()->status);
    }

    public function test_superseded_timeout_job_cannot_execute_a_resumed_run(): void
    {
        $auto = $this->workflow();
        $run = $this->trigger($auto, $this->message());
        $this->execute($run);
        $deadline = $run->fresh()->wake_at->timestamp;
        $this->trigger($auto, $this->message('Alice'));
        (new ExecuteAutomationRunJob($run->id, $deadline))->handle(app(AutomationEngine::class));
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertSame(1, Message::where('direction', 'out')->count());
        $this->execute($run->fresh());
        $this->assertSame('completed', $run->fresh()->status);
    }
}
