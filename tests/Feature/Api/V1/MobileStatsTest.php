<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_are_workspace_scoped_unpaginated_and_limited_to_omni_channels(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        ['workspace' => $foreignWorkspace] = $this->createSubscribedWorkspaceContext();

        $agent = User::factory()->create(['workspace_id' => $workspace->id]);
        $webchat = $this->createChannelContext($workspace->id, 'webchat');
        $whatsapp = $this->createChannelContext($workspace->id, 'whatsapp');
        $email = $this->createChannelContext($workspace->id, 'email');
        $foreign = $this->createChannelContext($foreignWorkspace->id, 'webchat');

        foreach (range(1, 31) as $index) {
            Conversation::create([
                'workspace_id' => $workspace->id,
                'channel_account_id' => $webchat['account']->id,
                'contact_id' => $webchat['contact']->id,
                'status' => 'open',
                'unread_count' => $index === 31 ? 2 : 0,
            ]);
        }

        Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $webchat['account']->id,
            'contact_id' => $webchat['contact']->id,
            'assigned_user_id' => $agent->id,
            'status' => 'open',
            'unread_count' => 3,
        ]);
        Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $whatsapp['account']->id,
            'contact_id' => $whatsapp['contact']->id,
            'status' => 'resolved',
            'unread_count' => 0,
        ]);

        foreach ([$email, $foreign] as $excluded) {
            Conversation::create([
                'workspace_id' => $excluded['account']->workspace_id,
                'channel_account_id' => $excluded['account']->id,
                'contact_id' => $excluded['contact']->id,
                'status' => 'open',
                'unread_count' => 10,
            ]);
        }

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/mobile/stats')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'total_conversations' => 33,
                    'open' => 32,
                    'unread' => 5,
                    'unread_conversations' => 2,
                    'assigned' => 1,
                    'resolved' => 1,
                    'channels' => [
                        'webchat' => 32,
                        'whatsapp' => 1,
                    ],
                ],
            ]);
    }

    /** @return array{account: ChannelAccount, contact: Contact} */
    private function createChannelContext(int $workspaceId, string $channel): array
    {
        return [
            'account' => ChannelAccount::create([
                'workspace_id' => $workspaceId,
                'channel' => $channel,
                'provider' => $channel,
                'display_name' => ucfirst($channel),
                'status' => 'active',
            ]),
            'contact' => Contact::create([
                'workspace_id' => $workspaceId,
                'source' => $channel,
                'first_name' => ucfirst($channel),
            ]),
        ];
    }
}
