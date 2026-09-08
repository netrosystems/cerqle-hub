<?php

namespace Tests\Feature\Whatsapp;

use App\Events\MessageReceived;
use App\Events\MessageSent;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Jobs\ProcessCoexistenceEchoJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappEchoReceipt;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\CoexistenceMessageStore;
use App\Modules\Whatsapp\Services\CoexistenceWebhookIngress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoexistenceWebhookIngressTest extends TestCase
{
    use RefreshDatabase;

    private function setupPhone(): WhatsappPhoneNumber
    {
        $context = $this->createSubscribedWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $context['workspace']->id]);
        $phone = WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id,
            'phone_number_id' => 'PHONE_'.$waba->id, 'display_phone' => '+15550000001',
            'connection_mode' => 'coexistence']);
        ChannelAccount::create(['workspace_id' => $context['workspace']->id, 'channel' => 'whatsapp',
            'phone_number_id' => $phone->phone_number_id, 'business_account_id' => $waba->waba_id,
            'display_name' => 'Demo', 'status' => 'active']);

        return $phone;
    }

    private function entry(WhatsappPhoneNumber $phone): array
    {
        return [['id' => $phone->businessAccount->waba_id, 'changes' => [[
            'field' => 'smb_message_echoes', 'value' => [
                'metadata' => ['phone_number_id' => $phone->phone_number_id],
                'message_echoes' => [['id' => 'ECHO_1', 'from' => '15550000001', 'to' => '15550000002',
                    'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Private demo text']]],
            ],
        ]]]];
    }

    public function test_echo_is_encrypted_durable_and_replay_safe_without_inbound_side_effects(): void
    {
        Queue::fake();
        Event::fake([MessageReceived::class, MessageSent::class]);
        $phone = $this->setupPhone();
        $ingress = app(CoexistenceWebhookIngress::class);
        $ingress->capture($this->entry($phone));
        $ingress->capture($this->entry($phone));
        $this->assertDatabaseCount('whatsapp_echo_receipts', 1);
        $this->assertStringNotContainsString('Private demo text', DB::table('whatsapp_echo_receipts')->value('payload'));
        $receipt = WhatsappEchoReceipt::first();
        Queue::assertPushed(ProcessCoexistenceEchoJob::class, fn ($job) => $job->receiptId === $receipt->id && $job->queue === 'whatsapp');
        $job = new ProcessCoexistenceEchoJob($receipt->id);
        $job->handle(app(CoexistenceMessageStore::class));
        $job->handle(app(CoexistenceMessageStore::class));
        $this->assertDatabaseCount('messages', 1);
        $this->assertNull($receipt->fresh()->payload);
        $this->assertSame('done', $receipt->fresh()->status);
        Event::assertNotDispatched(MessageReceived::class);
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }

    public function test_wrong_waba_or_standard_phone_cannot_accept_echoes(): void
    {
        Queue::fake();
        $phone = $this->setupPhone();
        $ingress = app(CoexistenceWebhookIngress::class);
        $ingress->capture($this->entry($phone), 'ANOTHER_WABA');
        $wrong = $this->entry($phone);
        $wrong[0]['id'] = 'ANOTHER_WABA';
        $ingress->capture($wrong);
        $phone->update(['connection_mode' => 'cloud_api']);
        $ingress->capture($this->entry($phone));
        $this->assertDatabaseCount('whatsapp_echo_receipts', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_rechecks_workspace_ownership(): void
    {
        Queue::fake();
        $phone = $this->setupPhone();
        app(CoexistenceWebhookIngress::class)->capture($this->entry($phone));
        $receipt = WhatsappEchoReceipt::first();
        $other = $this->createSubscribedWorkspaceContext();
        $phone->businessAccount->update(['workspace_id' => $other['workspace']->id]);
        (new ProcessCoexistenceEchoJob($receipt->id))->handle(app(CoexistenceMessageStore::class));
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame('ignored', $receipt->fresh()->status);
    }

    public function test_history_and_contacts_are_not_imported_in_this_pilot(): void
    {
        Queue::fake();
        $phone = $this->setupPhone();
        foreach (['history', 'smb_app_state_sync'] as $field) {
            $entry = $this->entry($phone);
            $entry[0]['changes'][0]['field'] = $field;
            app(CoexistenceWebhookIngress::class)->capture($entry);
        }
        $this->assertDatabaseCount('whatsapp_echo_receipts', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_partner_removal_disables_only_the_matching_coexistence_channel(): void
    {
        Queue::fake();
        $phone = $this->setupPhone();
        $other = $this->setupPhone();
        app(CoexistenceWebhookIngress::class)->capture([[
            'id' => $phone->businessAccount->waba_id,
            'changes' => [['field' => 'account_update', 'value' => ['event' => 'PARTNER_REMOVED']]],
        ]]);
        $this->assertNotNull($phone->fresh()->coexistence_meta['disconnected_at']);
        $this->assertSame('inactive', ChannelAccount::where('phone_number_id', $phone->phone_number_id)->value('status'));
        $this->assertSame('active', ChannelAccount::where('phone_number_id', $other->phone_number_id)->value('status'));
        $this->assertDatabaseCount('whatsapp_phone_numbers', 2);
    }
}
