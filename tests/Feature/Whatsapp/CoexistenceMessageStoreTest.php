<?php

namespace Tests\Feature\Whatsapp;

use App\Events\MessageReceived;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\CoexistenceMessageStore;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoexistenceMessageStoreTest extends TestCase
{
    use RefreshDatabase;

    private function phone(): WhatsappPhoneNumber
    {
        $context = $this->createSubscribedWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $context['workspace']->id]);
        $phone = WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id, 'phone_number_id' => 'PHONE_'.$waba->id,
            'display_phone' => '+15550000001', 'connection_mode' => 'coexistence',
            'coexistence_meta' => ['import_consent' => true],
        ]);
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id, 'channel' => 'whatsapp',
            'display_name' => 'Demo', 'phone_number_id' => $phone->phone_number_id,
            'business_account_id' => $waba->waba_id, 'status' => 'active',
        ]);

        return $phone;
    }

    private function payload(string $id = 'DEMO_MESSAGE'): array
    {
        return [
            'id' => $id, 'from' => '15550000002', 'timestamp' => (string) now()->subMinute()->timestamp,
            'type' => 'text', 'text' => ['body' => 'start'],
        ];
    }

    private function store(WhatsappPhoneNumber $phone, array $payload, bool $history = true): ?Message
    {
        return app(CoexistenceMessageStore::class)->store(
            $phone->businessAccount->waba_id, $phone->phone_number_id, $payload, $history,
        );
    }

    public function test_history_does_not_grant_consent_open_window_trigger_ai_or_mark_unread(): void
    {
        $phone = $this->phone();
        Http::preventStrayRequests();
        Event::fake([MessageReceived::class]);
        $message = $this->store($phone, $this->payload());
        $this->assertNotNull($message);
        $this->assertSame('whatsapp_history', $message->origin);
        $this->assertFalse($message->conversation->contact->opt_in_whatsapp);
        $this->assertFalse($message->conversation->contact->opt_in_sms);
        $this->assertFalse($message->conversation->isWhatsappWindowOpen());
        $this->assertSame(0, $message->conversation->unread_count);
        $this->assertNull($message->conversation->last_inbound_at);
        Event::assertNotDispatched(MessageReceived::class);
        Http::assertNothingSent();
    }

    public function test_imported_messages_cannot_accidentally_be_resent(): void
    {
        $phone = $this->phone();
        $message = $this->store($phone, $this->payload());
        Http::preventStrayRequests();
        try {
            app(WhatsappDriver::class)->send($message);
            $this->fail('History was accepted for API sending.');
        } catch (\DomainException $exception) {
            $this->assertSame('Imported or mobile-app messages cannot be resent through the API.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_a_mobile_reply_stops_an_already_prepared_bot_reply(): void
    {
        $phone = $this->phone();
        $phone->businessAccount->update(['credentials' => ['access_token' => 'test-token']]);
        $history = $this->store($phone, $this->payload());
        $conversation = $history->conversation;
        $conversation->update(['assigned_to' => 'bot', 'handover_at' => null]);
        $reply = Message::create([
            'conversation_id' => $conversation->id, 'channel' => 'whatsapp',
            'direction' => 'out', 'type' => 'text', 'body' => 'Prepared reply',
            'status' => 'queued', 'sent_by' => 'bot', 'origin' => 'live',
        ]);
        $reply->setRelation('conversation', $conversation);
        $this->store($phone, array_merge($this->payload('ECHO'), [
            'from' => '15550000001', 'to' => '15550000002',
        ]), false);
        Http::preventStrayRequests();
        try {
            app(WhatsappDriver::class)->send($reply);
            $this->fail('Stale bot reply was accepted after takeover.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('handed to a human', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_duplicate_history_is_idempotent_but_same_id_on_other_phone_is_independent(): void
    {
        $phone = $this->phone();
        $first = $this->store($phone, $this->payload());
        $this->assertSame($first->id, $this->store($phone, $this->payload())->id);
        $other = $this->phone();
        $second = $this->store($other, $this->payload());
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->conversation->workspace_id, $second->conversation->workspace_id);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_history_is_rejected_without_consent_or_for_a_different_waba(): void
    {
        $phone = $this->phone();
        $this->assertNull(app(CoexistenceMessageStore::class)->store('WRONG_WABA', $phone->phone_number_id, $this->payload(), true));
        $phone->update(['coexistence_meta' => ['import_consent' => false]]);
        $this->assertNull($this->store($phone, $this->payload()));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_mobile_reply_pauses_bot_and_does_not_trigger_an_inbound_event(): void
    {
        $phone = $this->phone();
        Event::fake([MessageReceived::class]);
        $payload = array_merge($this->payload(), ['from' => '15550000001', 'to' => '15550000002']);
        $message = $this->store($phone, $payload, false);
        $this->assertSame('whatsapp_business_app', $message->origin);
        $this->assertSame('out', $message->direction);
        $this->assertNull($message->user_id);
        $this->assertSame('human', $message->conversation->assigned_to);
        $this->assertNotNull($message->conversation->handover_at);
        $this->assertFalse($message->conversation->isWhatsappWindowOpen());
        Event::assertNotDispatched(MessageReceived::class);
    }

    public function test_media_followup_enriches_history_placeholder_without_duplicates(): void
    {
        $phone = $this->phone();
        $placeholder = array_merge($this->payload(), ['type' => 'media_placeholder']);
        $first = $this->store($phone, $placeholder);
        $media = array_merge($this->payload(), ['type' => 'image', 'image' => ['id' => 'DEMO_MEDIA', 'caption' => 'Demo']]);
        $second = $this->store($phone, $media);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('image', $second->type);
        $this->assertSame('Demo', $second->body);
        $this->assertSame('whatsapp_history', $second->origin);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_import_does_not_resurrect_deleted_contacts_or_accept_future_dates(): void
    {
        $phone = $this->phone();
        $first = $this->store($phone, $this->payload());
        $first->conversation->contact->delete();
        $this->assertNull($this->store($phone, $this->payload('SECOND')));
        $this->assertNull($this->store($phone, array_merge($this->payload('THIRD'), ['timestamp' => '9999999999999999999999'])));
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_replayed_history_does_not_replace_a_live_message(): void
    {
        $phone = $this->phone();
        $message = $this->store($phone, $this->payload());
        $message->update(['origin' => 'live', 'body' => 'Live response']);
        $replayed = $this->store($phone, $this->payload());
        $this->assertSame('live', $replayed->origin);
        $this->assertSame('Live response', $replayed->body);
        $this->assertTrue($replayed->conversation->isWhatsappWindowOpen());
    }
}
