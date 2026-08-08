<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reproduces the 419 "Page Expired" seen on the client panel while an admin
 * is impersonating a client. The flow is exactly what the SPA does:
 *
 *   1. Admin signs in and POSTs /admin/clients/{id}/impersonate.
 *      The server rotates the session id (Auth::login) and sets the
 *      impersonation markers.
 *   2. Browser follows the redirect to the client dashboard.
 *      HandleInertiaRequests shares the new csrf_token on every response,
 *      and app.jsx syncs it into axios + <meta>.
 *   3. User clicks "Return to Admin" -> router.post(admin.impersonation.stop)
 *      with the synced X-CSRF-TOKEN header.
 *
 * If the synced token does not match the server-side session token at any
 * step, the POST 419s. This test pins the contract.
 *
 * NOTE: phpunit.xml sets SESSION_DRIVER=array, which makes each request a
 * brand new session and therefore can't reproduce the rotation. We force
 * the file driver via a per-test config override so the session survives
 * across requests inside the test — matching how a browser keeps the
 * cookie between page loads.
 */
class ImpersonationCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
    }

    #[Test]
    public function impersonation_stop_redirects_when_token_is_synced(): void
    {
        // 1. Admin signs in.
        $admin = $this->createSuperAdmin([
            'email' => 'admin@cerqle.test',
            'password' => bcrypt('password'),
        ]);

        $this->post('/login', [
            'email' => 'admin@cerqle.test',
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($admin, 'admin');

        // 2. Set up a client + active client user to impersonate.
        $client = Client::create([
            'name' => 'Acme',
            'email' => 'acme@cerqle.test',
            'status' => Client::STATUS_ACTIVE,
        ]);
        User::factory()->create([
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $tokenBefore = $this->app['session']->token();

        // 3. Admin impersonates the client user.
        $this->post("/admin/clients/{$client->id}/impersonate")
            ->assertRedirect(route('client.dashboard'));
        $this->assertTrue($this->app['session']->get('impersonating'));

        // 4. Simulate the SPA syncing the (rotated) CSRF token after the
        //    redirect response. This is exactly what HandleInertiaRequests
        //    shares and what app.jsx's syncCsrfToken() copies into axios
        //    and the meta tag.
        $rotatedToken = $this->app['session']->token();
        $this->assertNotSame(
            $tokenBefore,
            $rotatedToken,
            'Session token must rotate on Auth::login() so the impersonated session is fresh',
        );

        // 5. User clicks "Return to Admin" — Inertia fires
        //    router.post(route('admin.impersonation.stop')) with the synced
        //    X-CSRF-TOKEN header.
        $stopResponse = $this->post(
            route('admin.impersonation.stop'),
            [],
            ['X-CSRF-TOKEN' => $rotatedToken],
        );

        $stopResponse->assertStatus(302);
        $stopResponse->assertRedirect(route('admin.clients.index'));
        // The session may already have been refreshed past this assertion
        // (file driver + cookie), so assert that the marker is either gone
        // or unset — not still truthy.
        $this->assertNull($this->app['session']->get('impersonating'));
    }

    #[Test]
    public function impersonation_stop_419s_if_caller_uses_the_pre_rotation_token(): void
    {
        // Locks in the failure mode the SPA must guard against: any caller
        // (or a stale axios header) using the boot-time token instead of
        // the post-rotation token will trip the CSRF middleware. This is
        // what the synced-csrf logic prevents in production.
        $admin = $this->createSuperAdmin([
            'email' => 'admin2@cerqle.test',
            'password' => bcrypt('password'),
        ]);

        $this->post('/login', [
            'email' => 'admin2@cerqle.test',
            'password' => 'password',
        ])->assertRedirect();

        $client = Client::create([
            'name' => 'Acme 2',
            'email' => 'acme2@cerqle.test',
            'status' => Client::STATUS_ACTIVE,
        ]);
        User::factory()->create([
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $tokenBefore = $this->app['session']->token();

        $imp = $this->post("/admin/clients/{$client->id}/impersonate");
        $imp->assertRedirect();

        // Try to stop impersonation using the *stale* token — no sync.
        $stop = $this->post(
            route('admin.impersonation.stop'),
            [],
            ['X-CSRF-TOKEN' => $tokenBefore],
        );
        dump('stop status=', $stop->getStatusCode(),
             ' stop location=', $stop->headers->get('Location'),
        );
        $stop->assertStatus(419);
    }
}
