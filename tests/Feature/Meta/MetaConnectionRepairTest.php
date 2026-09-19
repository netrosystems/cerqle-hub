<?php

namespace Tests\Feature\Meta;

use App\Modules\Inbox\Http\Controllers\InboxSetupController;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConnectionRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_repair_registers_webhook_and_resubscribes_page(): void
    {
        $this->withoutMiddleware();
        config(['app.url' => 'https://cerqle.test']);
        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => 'meta-app-id',
                'app_secret' => 'meta-app-secret',
                'verify_token' => 'verify-token',
            ],
        ]);
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $user->forceFill(['workspace_id' => $workspace->id])->save();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'provider' => 'meta',
            'display_name' => 'Instagram',
            'credentials' => ['access_token' => 'page-token', 'instagram_account_id' => 'ig-1'],
            'meta_json' => [
                'instagram_page_id' => 'ig-1',
                'instagram_account_id' => 'ig-1',
                'facebook_page_id' => 'page-1',
            ],
            'status' => 'active',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/meta-app-id/subscriptions*' => Http::response(['success' => true]),
            'https://graph.facebook.com/v25.0/page-1/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $request = Request::create('/app/inbox/setup/'.$account->id.'/repair', 'POST');
        $request->setUserResolver(fn () => $user);
        $response = app(InboxSetupController::class)->repairMetaConnection($request, $account);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) $response->getData(true)['success']);

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/meta-app-id/subscriptions'
            && $request['object'] === 'instagram');
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/page-1/subscribed_apps'
            && $request->hasHeader('Authorization', 'Bearer page-token'));
        $this->assertNotNull($account->fresh()->meta_json['webhook_repaired_at'] ?? null);
    }
}
