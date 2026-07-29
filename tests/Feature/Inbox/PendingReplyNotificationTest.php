<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\PendingCustomerReplyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PendingReplyNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_admin_is_emailed_once_when_customer_waits_over_one_hour(): void
    {
        Notification::fake();
        ['workspace' => $workspace, 'user' => $admin] = $this->createWorkspaceContext();
        $admin->update(['status' => 'active', 'client_role' => 'administrator']);

        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Mina',
            'last_name' => 'Rahman',
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website',
            'status' => 'active',
        ]);
        $receivedAt = now()->subMinutes(61);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
            'last_inbound_at' => $receivedAt,
            'last_message_at' => $receivedAt,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Can somebody help with my order?',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => $receivedAt,
        ]);

        $this->artisan('inbox:notify-unanswered')->assertSuccessful();

        Notification::assertSentTo(
            $admin,
            PendingCustomerReplyNotification::class,
            function (PendingCustomerReplyNotification $notification, array $channels) use ($admin): bool {
                $mail = $notification->toMail($admin);

                return in_array('mail', $channels, true)
                    && in_array('database', $channels, true)
                    && $mail->subject === 'Customer waiting for a reply: Mina Rahman';
            }
        );
        $this->assertNotNull($conversation->fresh()->pending_reply_notified_at);

        $this->artisan('inbox:notify-unanswered')->assertSuccessful();
        Notification::assertSentToTimes($admin, PendingCustomerReplyNotification::class, 1);
    }

    public function test_answered_conversation_does_not_send_pending_reply_email(): void
    {
        Notification::fake();
        ['workspace' => $workspace, 'user' => $admin] = $this->createWorkspaceContext();
        $admin->update(['status' => 'active', 'client_role' => 'administrator']);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website',
            'status' => 'active',
        ]);
        $receivedAt = now()->subMinutes(75);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
            'last_inbound_at' => $receivedAt,
            'last_message_at' => now()->subMinutes(70),
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Hello?',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => $receivedAt,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'How can we help?',
            'status' => 'sent',
            'sent_by' => 'human',
            'user_id' => $admin->id,
            'sent_at' => now()->subMinutes(70),
        ]);

        $this->artisan('inbox:notify-unanswered')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($conversation->fresh()->pending_reply_notified_at);
    }
}
