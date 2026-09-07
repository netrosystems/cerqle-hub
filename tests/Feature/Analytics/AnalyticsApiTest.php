<?php

namespace Tests\Feature\Analytics;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\AnalyticsService;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    // ─── Auth & scope guards ──────────────────────────────────────────────────

    public function test_messages_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/analytics/messages')->assertStatus(401);
    }

    public function test_messages_endpoint_requires_analytics_scope(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('t', [ApiAbilities::CONTACTS_READ])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/analytics/messages')->assertStatus(403);
    }

    // ─── Messages ─────────────────────────────────────────────────────────────

    public function test_messages_endpoint_returns_ok(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('t', [ApiAbilities::ANALYTICS_READ])->plainTextToken;

        $conv = Conversation::create([
            'workspace_id' => $ws->id, 'channel_account_id' => 1, 'contact_id' => 1, 'status' => 'open',
        ]);
        Message::create([
            'conversation_id' => $conv->id, 'direction' => 'out',
            'channel' => 'whatsapp', 'type' => 'text', 'body' => 'hi', 'status' => 'sent',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/analytics/messages')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['from', 'to']]);
    }

    // ─── AI usage ─────────────────────────────────────────────────────────────

    public function test_ai_usage_endpoint_returns_ok(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('t', [ApiAbilities::ANALYTICS_READ])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/analytics/ai-usage')
            ->assertOk()
            ->assertJsonStructure(['data' => ['kpis', 'tokens_by_day', 'tokens_by_model'], 'meta']);
    }

    // ─── Campaign funnel ──────────────────────────────────────────────────────

    public function test_campaign_funnel_endpoint_returns_ok(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('t', [ApiAbilities::ANALYTICS_READ])->plainTextToken;

        $campaign = Campaign::create([
            'workspace_id' => $ws->id, 'name' => 'Funnel', 'channel' => 'sms',
            'audience_type' => 'segment', 'status' => 'completed',
        ]);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => 1, 'status' => 'delivered']);

        $this->withToken($token)
            ->getJson("/api/v1/analytics/campaign/{$campaign->id}/funnel")
            ->assertOk()
            ->assertJsonStructure(['data' => ['funnel', 'delivery_over_time', 'failed_reasons']]);
    }

    public function test_campaign_funnel_returns_403_for_other_workspace(): void
    {
        $ctx1 = $this->createWorkspaceContext();
        $ctx2 = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($ctx2['user']);
        $token2 = $ctx2['user']->createToken('t', [ApiAbilities::ANALYTICS_READ])->plainTextToken;

        $campaign = Campaign::create([
            'workspace_id' => $ctx1['workspace']->id, 'name' => 'Other', 'channel' => 'sms',
            'audience_type' => 'segment', 'status' => 'completed',
        ]);

        $this->withToken($token2)
            ->getJson("/api/v1/analytics/campaign/{$campaign->id}/funnel")
            ->assertStatus(403);
    }

    public function test_delivery_hour_buckets_exclude_other_campaigns(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $campaign = Campaign::factory()->create(['workspace_id' => $workspace->id]);
        $other = Campaign::factory()->create(['workspace_id' => $workspace->id]);
        foreach ([$campaign, $other] as $item) {
            CampaignRecipient::create([
                'campaign_id' => $item->id,
                'contact_id' => 1,
                'status' => 'delivered',
                'sent_at' => '2026-09-07 10:15:00',
                'delivered_at' => '2026-09-07 11:05:00',
            ]);
        }

        $this->assertSame([
            ['hour' => '2026-09-07 10:00', 'sent' => 1, 'delivered' => 0, 'read' => 0],
            ['hour' => '2026-09-07 11:00', 'sent' => 0, 'delivered' => 1, 'read' => 0],
        ], app(AnalyticsService::class)->campaignDeliveryOverTime($campaign->id));
    }

    // ─── Conversations ────────────────────────────────────────────────────────

    public function test_conversations_endpoint_returns_ok(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('t', [ApiAbilities::ANALYTICS_READ])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/analytics/conversations')
            ->assertOk()
            ->assertJsonStructure(['data' => ['over_time', 'channel_mix'], 'meta']);
    }
}
