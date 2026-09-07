<?php

namespace Tests\Feature\Meta;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessengerPageSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        IntegrationConfig::create([
            'provider' => 'meta_app', 'label' => 'Meta', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['app_id' => 'test-app', 'app_secret' => 'test-secret'],
        ]);
        Http::preventStrayRequests();
    }

    public function test_selected_page_can_fetch_missing_page_token_from_pending_authorization(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Http::fake([
            'graph.facebook.com/*/test-page/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/*/test-page*' => Http::response(['access_token' => 'test-page-token']),
        ]);

        $response = $this->actingAs($user)->withSession([
            'messenger_connect_selection.test-selection' => [
                'workspace_id' => $workspace->id,
                'pages' => [['id' => 'test-page', 'name' => 'Test page']],
                'user_access_token' => 'test-user-token',
            ],
        ])->postJson(route('client.inbox.setup.embedded-signup.messenger'), [
            'selection_token' => 'test-selection', 'selected_facebook_page_id' => 'test-page',
        ]);

        $response->assertOk()->assertJsonPath('connected', 1);
        $response->assertSessionMissing('messenger_connect_selection.test-selection');
        $this->assertSame('test-page-token', ChannelAccount::sole()->credentials['page_access_token']);
        $this->assertStringNotContainsString('test-user-token', $response->getContent());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fields=access_token')
            && $request->hasHeader('Authorization', 'Bearer test-user-token'));
    }

    public function test_legacy_pending_selection_without_any_token_does_not_send_unauthenticated_request(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Http::fake();
        $this->actingAs($user)->withSession([
            'messenger_connect_selection.test-selection' => [
                'workspace_id' => $workspace->id, 'pages' => [['id' => 'test-page']],
            ],
        ])->postJson(route('client.inbox.setup.embedded-signup.messenger'), [
            'selection_token' => 'test-selection', 'selected_facebook_page_id' => 'test-page',
        ])->assertUnprocessable();

        Http::assertNothingSent();
        $this->assertDatabaseCount('channel_accounts', 0);
    }
}
