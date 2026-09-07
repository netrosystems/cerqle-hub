<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Whatsapp\Models\WhatsappWidget;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\ChannelPlanLimitService;
use App\Services\MessagingMessageLimitService;
use App\Services\WorkspaceDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ChannelPlanLimitTest extends TestCase
{
    use RefreshDatabase;

    private function context(array $limits): array
    {
        $ctx = $this->createWorkspaceContext();
        $ctx['plan'] = Plan::factory()->create(['limits' => $limits]);
        $this->attachPlanToClient($ctx['client'], $ctx['plan']);

        return $ctx;
    }

    private function channel(Workspace $workspace, string $channel = 'whatsapp', string $id = 'one'): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $workspace->id, 'channel' => $channel, 'phone_number_id' => $id,
            'display_name' => 'Test', 'status' => 'active', 'meta_json' => ['page_id' => $id, 'instagram_page_id' => $id],
        ]);
    }

    public function test_messaging_channels_pool_across_networks_and_workspaces(): void
    {
        $ctx = $this->context(['messaging_channels' => 2]);
        $second = Workspace::create(['client_id' => $ctx['client']->id, 'owner_id' => $ctx['user']->id, 'name' => 'Second']);
        $this->channel($ctx['workspace']);
        $this->channel($second, 'messenger');
        $usage = app(ChannelPlanLimitService::class)->usage($ctx['workspace'], 'messaging_channels');
        $this->assertSame(2, $usage['used']);
        $this->assertSame(0, $usage['remaining']);
        $this->expectException(ValidationException::class);
        $this->channel($second, 'instagram');
    }

    public function test_reconnect_downgrade_inactive_and_delete_behaviour(): void
    {
        $ctx = $this->context(['messaging_channels' => 1]);
        $first = $this->channel($ctx['workspace']);
        $first->update(['status' => 'inactive']);
        $ctx['plan']->update(['limits' => ['messaging_channels' => 0]]);
        $this->assertSame($first->id, $this->channel($ctx['workspace'])->id);
        $this->assertSame(1, ChannelAccount::count());
        $this->assertTrue(app(ChannelPlanLimitService::class)->usage($ctx['workspace'], 'messaging_channels')['is_full']);
        $first->delete();
        $ctx['plan']->update(['limits' => ['messaging_channels' => 1]]);
        $this->channel($ctx['workspace'], 'instagram');
        $this->assertSame(1, ChannelAccount::count());
    }

    public function test_legacy_finite_limits_and_explicit_unlimited_override(): void
    {
        $ctx = $this->context(['whatsapp_accounts' => 0]);
        $this->assertSame(0, app(ChannelPlanLimitService::class)->usage($ctx['workspace'], 'messaging_channels')['limit']);
        $ctx['plan']->update(['limits' => ['whatsapp_accounts' => 0, 'messaging_channels' => null]]);
        $this->channel($ctx['workspace']);
        $this->assertTrue(app(ChannelPlanLimitService::class)->usage($ctx['workspace'], 'messaging_channels')['unlimited']);
    }

    public function test_email_and_webchat_do_not_consume_messaging_slots(): void
    {
        $ctx = $this->context(['messaging_channels' => 0]);
        $this->channel($ctx['workspace'], 'email');
        $this->channel($ctx['workspace'], 'webchat');
        $this->assertSame(0, app(ChannelPlanLimitService::class)->usage($ctx['workspace'], 'messaging_channels')['used']);
        $this->expectException(ValidationException::class);
        $this->channel($ctx['workspace']);
    }

    public function test_widget_and_wa_chatbot_have_independent_limits(): void
    {
        $ctx = $this->context(['website_widgets' => 1, 'whatsapp_chatbots' => 0]);
        $channel = $this->channel($ctx['workspace'], 'webchat');
        $widget = ChatWidget::create(['workspace_id' => $ctx['workspace']->id, 'channel_account_id' => $channel->id, 'name' => 'Widget']);
        $widget->update(['enabled' => false]);
        $this->assertSame(1, app(ChannelPlanLimitService::class)->usage($ctx['workspace'], 'website_widgets')['used']);
        $this->expectException(ValidationException::class);
        WhatsappWidget::create(['workspace_id' => $ctx['workspace']->id, 'display_phone' => '1234']);
    }

    public function test_social_limit_blocks_new_accounts_but_not_oauth_reauthorization(): void
    {
        $ctx = $this->context(['social_accounts' => 1]);
        $attrs = ['workspace_id' => $ctx['workspace']->id, 'network' => 'youtube', 'account_id' => 'channel', 'name' => 'Test', 'active' => true, 'access_token' => 'test-token'];
        $first = SocialAccount::create($attrs);
        $again = SocialAccount::create($attrs + ['access_token' => 'test-token']);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('test-token', $first->fresh()->access_token);
        $this->assertStringNotContainsString('test-token', $first->fresh()->toJson());
        $this->expectException(ValidationException::class);
        SocialAccount::create(array_merge($attrs, ['network' => 'facebook']));
    }

    public function test_organization_isolation_and_no_plan_fail_closed(): void
    {
        $ctx = $this->context(['messaging_channels' => 1]);
        $this->channel($ctx['workspace']);
        $other = $this->context(['messaging_channels' => 1]);
        $this->channel($other['workspace']);
        $noPlan = $this->createWorkspaceContext();
        $this->expectException(ValidationException::class);
        $this->channel($noPlan['workspace']);
    }

    public function test_messages_reserve_before_send_and_refund_failures(): void
    {
        $ctx = $this->context(['messaging_messages_per_month' => 1]);
        $service = app(MessagingMessageLimitService::class);
        try {
            $service->send($ctx['workspace']->id, fn () => throw new \RuntimeException('rejected'));
        } catch (\RuntimeException $e) {
            $this->assertSame('rejected', $e->getMessage());
        }
        $this->assertSame(0, $service->usage($ctx['workspace'])['used']);
        $service->send($ctx['workspace']->id, function () use ($service, $ctx) {
            $this->assertSame(1, $service->usage($ctx['workspace'])['used']);
            try {
                $service->send($ctx['workspace']->id, fn () => $this->fail('Over quota provider call'));
                $this->fail('Expected quota rejection');
            } catch (ValidationException) {
                return 'sent';
            }
        });
        $this->assertSame(1, $service->usage($ctx['workspace'])['used']);
    }

    public function test_monthly_reset_and_cross_workspace_message_pool(): void
    {
        $ctx = $this->context(['whatsapp_messages_per_month' => 1]);
        $second = Workspace::create(['client_id' => $ctx['client']->id, 'owner_id' => $ctx['user']->id, 'name' => 'Second']);
        $service = app(MessagingMessageLimitService::class);
        $service->send($second->id, fn () => 'sent');
        $this->assertTrue($service->usage($ctx['workspace'])['is_full']);
        $this->travelTo(now()->addMonth()->startOfMonth());
        $this->assertSame(0, $service->usage($ctx['workspace'])['used']);
        $service->send($ctx['workspace']->id, fn () => 'sent');
        $this->assertSame(1, $service->usage($second)['used']);
    }

    public function test_existing_usage_meter_accumulates_instead_of_resetting(): void
    {
        $ctx = $this->context([]);
        UsageMeter::track($ctx['workspace']->id, 'social_posts');
        UsageMeter::track($ctx['workspace']->id, 'social_posts');
        $this->assertSame(2, UsageMeter::current($ctx['workspace']->id, 'social_posts'));
    }

    public function test_whatsapp_transport_blocks_before_http_and_excludes_read_receipts(): void
    {
        $ctx = $this->context(['messaging_messages_per_month' => 0]);
        Http::fake(['*' => Http::response(['success' => true])]);
        $client = new CloudApiClient('test-phone', 'test-token', $ctx['workspace']->id);
        $client->markRead('test-message');
        Http::assertSentCount(1);
        try {
            $client->sendText('1234', 'Test');
            $this->fail('Expected quota rejection');
        } catch (ValidationException) {
            Http::assertSentCount(1);
        }
    }

    public function test_widget_http_rejection_does_not_leave_an_orphan_channel(): void
    {
        $ctx = $this->context(['website_widgets' => 0]);
        $this->actingAs($ctx['user'])->post(route('client.inbox.chat-widgets.store'), ['name' => 'Test', 'position' => 'bottom_right'])
            ->assertSessionHasErrors('plan_limit');
        $this->assertSame(0, ChatWidget::count());
        $this->assertSame(0, ChannelAccount::count());
    }

    public function test_inertia_exposes_inventory_usage(): void
    {
        $ctx = $this->context(['website_widgets' => 5]);
        $this->actingAs($ctx['user'])->get(route('client.inbox.chat-widgets.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channel_plan_usage.website_widgets.used', 0)
                ->where('channel_plan_usage.website_widgets.limit', 5)
                ->where('channel_plan_usage.website_widgets.remaining', 5));
    }

    public function test_workspace_deletion_cannot_reset_message_usage(): void
    {
        $ctx = $this->context(['messaging_messages_per_month' => 1]);
        $second = Workspace::create(['client_id' => $ctx['client']->id, 'owner_id' => $ctx['user']->id, 'name' => 'Temporary']);
        $service = app(MessagingMessageLimitService::class);
        $service->send($second->id, fn () => 'sent');
        app(WorkspaceDeletionService::class)->delete($second, $ctx['user']);
        $this->assertSame(1, $service->usage($ctx['workspace'])['used']);
        $this->expectException(ValidationException::class);
        $service->send($ctx['workspace']->id, fn () => $this->fail('Must not send after workspace deletion'));
    }

    public function test_migration_preserves_finite_plan_limits_without_overwriting_new_values(): void
    {
        $plan = Plan::factory()->create(['limits' => ['whatsapp_accounts' => 4, 'whatsapp_messages_per_month' => 100, 'messaging_channels' => 2]]);
        $migration = require database_path('migrations/2026_09_07_130000_unify_messaging_plan_limits.php');
        $migration->up();
        $this->assertSame(2, $plan->fresh()->limitValue('messaging_channels'));
        $this->assertSame(100, $plan->fresh()->limitValue('messaging_messages_per_month'));
        $this->assertNull($plan->fresh()->limitValue('website_widgets'));
    }

    public function test_rollout_seeds_existing_message_usage_once(): void
    {
        $ctx = $this->context(['whatsapp_messages_per_month' => 10]);
        $second = Workspace::create(['client_id' => $ctx['client']->id, 'owner_id' => $ctx['user']->id, 'name' => 'Second']);
        UsageMeter::track($ctx['workspace']->id, 'whatsapp_messages', 3);
        UsageMeter::track($second->id, 'whatsapp_messages', 2);
        Schema::drop('messaging_quota_periods');
        $migration = require database_path('migrations/2026_09_07_130000_unify_messaging_plan_limits.php');
        $migration->up();
        $migration->up();
        $usage = app(MessagingMessageLimitService::class)->usage($ctx['workspace']);
        $this->assertSame(5, $usage['used']);
        $this->assertSame(5, $usage['remaining']);
    }
}
