<?php

namespace Tests\Feature\Social;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\LinkedInPageDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class LinkedInPageConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function context(array $credentials = ['client_id' => 'page-app', 'client_secret' => 'page-secret']): array
    {
        $context = $this->createSubscribedWorkspaceContext();
        IntegrationConfig::create([
            'provider' => 'oauth_linkedin_page',
            'label' => 'LinkedIn Company Page',
            'enabled' => true,
            'credentials' => $credentials,
        ]);
        $this->actingAs($context['user']);

        return $context;
    }

    /** Drive the connect redirect and return its query string. */
    private function start(): array
    {
        $response = $this->get(route('client.social.accounts.connect', 'linkedin_page'));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_page_flow_uses_its_own_app_and_organisation_scopes(): void
    {
        $this->context();
        $query = $this->start();

        // The whole point of the second provider slot: the page flow must not
        // borrow the member app's client id.
        $this->assertSame('page-app', $query['client_id']);

        $scopes = explode(' ', $query['scope']);
        $this->assertContains('r_organization_admin', $scopes, 'Without this the callback cannot list administered pages.');
        $this->assertContains('w_organization_social', $scopes, 'Without this Cerqle cannot post as the page.');
        $this->assertNotContains('w_member_social', $scopes, 'The page app should not request member posting.');
    }

    public function test_member_and_page_apps_are_configured_independently(): void
    {
        $this->context();
        // Only the page provider is configured, so the member flow must fail
        // rather than silently reusing the page credentials.
        $response = $this->get(route('client.social.accounts.connect', 'linkedin'));
        $response->assertRedirect(route('client.social.accounts.index'));
        $response->assertSessionHas('error');
    }

    public function test_callback_connects_every_administered_page(): void
    {
        ['workspace' => $workspace] = $this->context();
        $query = $this->start();

        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'page-token', 'expires_in' => 5184000,
                'scope' => 'r_organization_admin w_organization_social',
            ]),
            'api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => [
                ['organization' => 'urn:li:organization:111', 'organization~' => ['id' => 111, 'localizedName' => 'Acme Ltd']],
                ['organization' => 'urn:li:organization:222', 'organization~' => ['id' => 222, 'localizedName' => 'Acme Labs']],
            ]]),
        ]);

        $response = $this->get(route('client.social.oauth.callback', 'linkedin_page').'?code=abc&state='.$query['state']);
        $response->assertRedirect(route('client.social.accounts.index'));

        $accounts = SocialAccount::where('workspace_id', $workspace->id)->where('network', 'linkedin_page')->get();
        $this->assertCount(2, $accounts, 'One authorisation can cover several pages; each must become its own destination.');
        $this->assertEqualsCanonicalizing(['111', '222'], $accounts->pluck('account_id')->all());
        $this->assertEqualsCanonicalizing(['Acme Ltd', 'Acme Labs'], $accounts->pluck('name')->all());
    }

    public function test_a_member_with_no_pages_is_told_why_rather_than_failing_silently(): void
    {
        ['workspace' => $workspace] = $this->context();
        $query = $this->start();

        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'page-token', 'expires_in' => 5184000]),
            'api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => []]),
        ]);

        $response = $this->get(route('client.social.oauth.callback', 'linkedin_page').'?code=abc&state='.$query['state']);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('admin', (string) session('error'));
        $this->assertSame(0, SocialAccount::where('workspace_id', $workspace->id)->count());
    }

    public function test_a_profile_and_a_page_can_be_connected_at_the_same_time(): void
    {
        ['workspace' => $workspace] = $this->context();

        SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'linkedin', 'account_id' => 'member-1', 'name' => 'A Person', 'access_token' => 't']);
        SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'linkedin_page', 'account_id' => '111', 'name' => 'Acme Ltd', 'access_token' => 't']);

        $this->assertSame(2, SocialAccount::where('workspace_id', $workspace->id)->count());
    }

    public function test_page_posts_are_authored_by_the_organisation_not_the_person(): void
    {
        ['workspace' => $workspace] = $this->context();
        $account = SocialAccount::create([
            'workspace_id' => $workspace->id, 'network' => 'linkedin_page',
            'account_id' => '111', 'name' => 'Acme Ltd', 'access_token' => 'page-token',
        ]);

        Http::fake(['api.linkedin.com/v2/ugcPosts' => Http::response(['id' => 'urn:li:share:9'], 201)]);

        $id = (new LinkedInPageDriver)->publish($account, ['body' => 'Hello from the page']);
        $this->assertSame('urn:li:share:9', $id);

        Http::assertSent(function ($request) {
            // urn:li:person: here would post to whoever authorised the app,
            // which is the bug this whole feature exists to avoid.
            return str_contains($request->url(), 'ugcPosts')
                && $request->data()['author'] === 'urn:li:organization:111';
        });
    }

    public function test_discovery_failure_points_at_the_missing_product(): void
    {
        $this->context();
        $query = $this->start();

        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'page-token', 'expires_in' => 5184000]),
            'api.linkedin.com/v2/organizationAcls*' => Http::response(['message' => 'Not enough permissions'], 403),
        ]);

        $response = $this->get(route('client.social.oauth.callback', 'linkedin_page').'?code=abc&state='.$query['state']);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Community Management API', (string) session('error'));
    }

    public function test_unconfigured_page_provider_tells_the_client_to_contact_an_administrator(): void
    {
        $context = $this->createSubscribedWorkspaceContext();
        $this->actingAs($context['user']);

        $response = $this->get(route('client.social.accounts.connect', 'linkedin_page'));
        $response->assertRedirect(route('client.social.accounts.index'));
        $this->assertStringContainsString('administrator', (string) session('error'));
    }

    protected function tearDown(): void
    {
        Session::flush();
        parent::tearDown();
    }
}
