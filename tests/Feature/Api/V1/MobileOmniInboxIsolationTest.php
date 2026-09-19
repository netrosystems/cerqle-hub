<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileOmniInboxIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_omni_inbox_excludes_email_and_sms_conversations(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();

        foreach (['webchat', 'whatsapp', 'email', 'sms'] as $channel) {
            $account = ChannelAccount::create([
                'workspace_id' => $workspace->id,
                'channel' => $channel,
                'provider' => $channel,
                'display_name' => ucfirst($channel),
                'status' => 'active',
            ]);
            $contact = Contact::create([
                'workspace_id' => $workspace->id,
                'source' => $channel,
                'first_name' => ucfirst($channel),
            ]);

            Conversation::create([
                'workspace_id' => $workspace->id,
                'channel_account_id' => $account->id,
                'contact_id' => $contact->id,
                'status' => 'open',
                'unread_count' => 1,
                'last_message_at' => now(),
            ]);
        }

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/mobile/conversations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['channel' => 'email'])
            ->assertJsonMissing(['channel' => 'sms']);

        $this->getJson('/api/v1/mobile/conversations?channel=email')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/mobile/inbox/setup')
            ->assertOk()
            ->assertJsonCount(2, 'channel_accounts')
            ->assertJsonMissing(['channel' => 'email'])
            ->assertJsonMissing(['channel' => 'sms']);

        $this->getJson('/api/v1/mobile/inbox/counts')
            ->assertOk()
            ->assertJsonPath('all', 2)
            ->assertJsonPath('mine', 0)
            ->assertJsonPath('unassigned', 2)
            ->assertJsonPath('unread', 2);
    }
}
