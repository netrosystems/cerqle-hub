<?php

namespace Tests\Feature\Client;

use App\Models\ClientSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_disable_pending_reply_notifications_from_settings(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), [
                'locale' => 'en',
                'display_currency' => 'USD',
                'theme' => 'light',
                'timezone' => 'Asia/Dhaka',
                'weekly_digest_enabled' => true,
                'pending_reply_notifications_enabled' => false,
            ])
            ->assertRedirect(route('client.settings.index'));

        $this->assertSame(
            '0',
            ClientSetting::get($client->id, 'pending_reply_notifications_enabled'),
        );

        $this->actingAs($user)
            ->get(route('client.settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('client/Settings/Index')
                ->where('pendingReplyNotificationsEnabled', false)
            );
    }
}
