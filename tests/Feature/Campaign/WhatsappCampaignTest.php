<?php

namespace Tests\Feature\Campaign;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Jobs\SendCampaignMessageJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Broadcasting\Services\WhatsappCampaignValidator;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Services\ClientAccessService;
use App\Services\MessagingMessageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return [$user->fresh(), $workspace];
    }

    private function connection(Workspace $workspace, string $wabaId = 'waba-1', string $phoneId = 'phone-1'): array
    {
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => $wabaId,
            'credentials' => ['system_user_token' => 'secret-token'],
        ]);
        $phone = WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id, 'phone_number_id' => $phoneId,
            'display_phone' => '+15550000001', 'verified_name' => 'Campaign line',
        ]);
        $channelId = DB::table('channel_accounts')->insertGetId([
            'workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'provider' => 'meta',
            'display_name' => 'Campaign line',
            'phone_number_id' => $phoneId, 'business_account_id' => $wabaId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $channel = ChannelAccount::findOrFail($channelId);
        $template = WhatsappTemplate::create([
            'workspace_id' => $workspace->id, 'waba_id' => $wabaId, 'name' => 'hello_campaign',
            'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']],
        ]);

        return compact('waba', 'phone', 'channel', 'template');
    }

    private function campaign(Workspace $workspace, array $overrides = []): Campaign
    {
        return Campaign::factory()->create(array_merge([
            'workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'status' => 'draft',
            'whatsapp_waba_id' => 'waba-1', 'whatsapp_phone_number_id' => 'phone-1',
            'template_ref' => ['name' => 'hello_campaign', 'language' => 'en', 'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '{{contact.first_name}}']]],
            ]],
        ], $overrides));
    }

    #[Test]
    public function a_valid_campaign_is_bound_to_its_exact_waba_phone_channel_and_template(): void
    {
        [, $workspace] = $this->context();
        $expected = $this->connection($workspace);

        $resolved = app(WhatsappCampaignValidator::class)->validate($this->campaign($workspace));

        $this->assertTrue($expected['waba']->is($resolved['waba']));
        $this->assertTrue($expected['phone']->is($resolved['phone']));
        $this->assertTrue($expected['channel']->is($resolved['channel']));
        $this->assertTrue($expected['template']->is($resolved['template']));
        $this->assertSame('phone-1', $resolved['client']->phoneNumberId());
    }

    #[Test]
    public function it_rejects_cross_waba_templates_inactive_channels_and_disconnected_phones(): void
    {
        [, $workspace] = $this->context();
        $assets = $this->connection($workspace);
        WhatsappTemplate::create([
            'workspace_id' => $workspace->id, 'waba_id' => 'another-waba', 'name' => 'wrong',
            'language' => 'en', 'status' => 'APPROVED', 'components' => [],
        ]);

        foreach ([
            fn () => $this->campaign($workspace, ['template_ref' => ['name' => 'wrong', 'language' => 'en']]),
            function () use ($workspace, $assets) {
                $assets['channel']->update(['status' => 'inactive']);

                return $this->campaign($workspace);
            },
            function () use ($workspace, $assets) {
                $assets['channel']->update(['status' => 'active']);
                $assets['phone']->update(['coexistence_meta' => ['disconnected_at' => now()->toIso8601String()]]);

                return $this->campaign($workspace);
            },
        ] as $makeCampaign) {
            try {
                app(WhatsappCampaignValidator::class)->validate($makeCampaign());
                $this->fail('Unsafe WhatsApp asset selection was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function a_valid_whatsapp_campaign_can_be_launched_and_scheduled_on_the_broadcast_queue(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->context();
        $this->connection($workspace);
        $campaign = $this->campaign($workspace);

        $this->actingAs($user)->post(route('client.campaigns.launch', $campaign))->assertRedirect();

        $this->assertSame('queued', $campaign->fresh()->status);
        Queue::assertPushed(LaunchCampaignJob::class, fn ($job) => $job->campaignId === $campaign->id);
    }

    #[Test]
    public function a_stale_scheduled_whatsapp_campaign_requires_review_instead_of_sending_late(): void
    {
        Queue::fake();
        [, $workspace] = $this->context();
        config(['broadcasting.whatsapp.stale_schedule_seconds' => 3600]);
        $campaign = $this->campaign($workspace, [
            'status' => 'queued',
            'schedule_at' => now()->subHours(2),
        ]);

        (new LaunchCampaignJob($campaign->id))->handle();

        $this->assertSame('safety_paused', $campaign->fresh()->status);
        $this->assertStringContainsString('scheduled delivery window', (string) $campaign->fresh()->pause_reason);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function sending_uses_the_selected_phone_endpoint_and_preserves_the_selected_inbox_channel(): void
    {
        [, $workspace] = $this->context();
        $assets = $this->connection($workspace);
        $campaign = $this->campaign($workspace, ['status' => 'sending']);
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id, 'phone_e164' => '+15550000002',
            'opt_in_whatsapp' => true, 'first_name' => 'Ada',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
            'status' => 'dispatching', 'attempts' => 0,
        ]);

        $access = $this->mock(ClientAccessService::class);
        $access->shouldReceive('allowsWorkspaceWrite')->once()->with($workspace->id)->andReturnTrue();
        $limit = $this->mock(MessagingMessageLimitService::class);
        $limit->shouldReceive('send')->once()->andReturnUsing(fn ($workspaceId, $send) => $send());
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.selected']]], 200)]);

        (new SendCampaignMessageJob($campaign->id, $contact->id))->handle(app(CampaignPersonalizer::class));

        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame('wamid.selected', $recipient->fresh()->provider_message_id);
        $this->assertDatabaseHas('conversations', ['workspace_id' => $workspace->id, 'channel_account_id' => $assets['channel']->id]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/phone-1/messages')
            && data_get($request->data(), 'template.name') === 'hello_campaign');
    }
}
