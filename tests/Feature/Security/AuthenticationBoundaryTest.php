<?php

namespace Tests\Feature\Security;

use App\Models\AdminUser;
use App\Models\MagicLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\FirebaseIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function mfaUser(): User
    {
        $user = $this->createWorkspaceContext()['user'];
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['ABCD-EFGH', 'IJKL-MNOP'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    public function test_first_party_api_mutations_require_csrf_and_keep_session_access(): void
    {
        $user = $this->createWorkspaceContext()['user'];
        config(['sanctum.stateful' => ['app.test']]);
        $this->enforceCsrfForNextRequests();
        $this->actingAs($user)->withHeaders(['Origin' => 'https://app.test'])
            ->withSession(['_token' => 'synthetic-csrf-token'])
            ->patchJson('/api/v1/me', ['name' => 'Unauthorised change'])->assertStatus(419);
        $this->assertNotSame('Unauthorised change', $user->fresh()->name);
        $this->withHeaders(['X-CSRF-TOKEN' => 'synthetic-csrf-token'])
            ->patchJson('/api/v1/me', ['name' => 'Authorised change'])->assertOk();
        $this->assertSame('Authorised change', $user->fresh()->name);
    }

    public function test_locked_admin_cannot_login_even_with_correct_password(): void
    {
        $admin = AdminUser::factory()->create();
        $key = strtolower($admin->email).'|127.0.0.1';
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key);
        }
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_password_and_magic_link_both_require_second_factor(): void
    {
        $user = $this->mfaUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('auth.two-factor.challenge'));
        $this->assertGuest();
        $link = MagicLink::create(['email' => $user->email, 'token' => 'test-link', 'expires_at' => now()->addMinutes(5)]);
        $this->get(route('auth.magic-link.verify', $link->token))->assertRedirect(route('auth.two-factor.challenge'));
        $this->assertGuest();
        $this->assertNotNull($link->fresh()->used_at);
    }

    public function test_second_factor_recovery_code_completes_login_once(): void
    {
        $user = $this->mfaUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('auth.two-factor.verify'), ['code' => 'ABCD-EFGH'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['IJKL-MNOP'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_expired_second_factor_challenge_cannot_login(): void
    {
        $user = $this->mfaUser();
        $this->withSession(['2fa_user_id' => $user->id, '2fa_expires_at' => now()->subSecond()->timestamp])
            ->post(route('auth.two-factor.verify'), ['code' => 'ABCD-EFGH'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_mobile_mfa_issues_no_token_until_code_verified(): void
    {
        $user = $this->mfaUser();
        $payload = ['email' => $user->email, 'password' => 'password'];
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(202)->assertJsonPath('code', 'two_factor_required')->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/auth/login', $payload + ['two_factor_code' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', $payload + ['two_factor_code' => 'ABCD-EFGH'])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/login', $payload + ['two_factor_code' => 'ABCD-EFGH'])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_firebase_login_obeys_mfa(): void
    {
        $user = $this->mfaUser();
        SystemSetting::set('firebase_enabled', 'true');
        SystemSetting::set('firebase_project_id', 'test-project');
        $this->mock(FirebaseIdTokenVerifier::class)->shouldReceive('verify')->once()->andReturn([
            'email' => $user->email, 'sub' => 'test-subject', 'email_verified' => true,
        ]);
        $this->postJson(route('auth.firebase'), ['id_token' => 'synthetic-token'])
            ->assertOk()->assertJsonPath('redirect', route('auth.two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_password_login(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_INACTIVE]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivation_revokes_tokens_sessions_and_remember_cookie(): void
    {
        $user = $this->createWorkspaceContext()['user'];
        $user->createToken('existing');
        DB::table('sessions')->insert([
            'id' => 'synthetic-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $user->update(['status' => User::STATUS_INACTIVE]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-session']);
        $this->assertNull($user->fresh()->remember_token);
        $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_inactive_client_blocks_existing_bearer_token(): void
    {
        $context = $this->createWorkspaceContext();
        $token = $context['user']->createToken('existing')->plainTextToken;
        $context['client']->update(['status' => 'inactive']);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_user_serialization_never_contains_mfa_secrets(): void
    {
        $user = $this->mfaUser();
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonMissingPath('two_factor_secret');
    }

    public function test_restricted_token_cannot_escalate_or_use_mobile_agent_surface(): void
    {
        $user = $this->createWorkspaceContext()['user'];
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('restricted', ['contacts:read'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/tokens', ['name' => 'escalated'])->assertForbidden();
        $this->getJson('/api/v1/mobile/conversations')->assertForbidden();
        $this->postJson('/api/v1/broadcasting/auth', ['channel_name' => 'private-user.'.$user->id])->assertForbidden();
        $this->getJson('/api/v1/contacts')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_limited_admin_cannot_grant_super_admin_role(): void
    {
        $super = $this->createSuperAdmin();
        $role = Role::create(['key' => 'limited', 'name' => 'Limited']);
        foreach (['create_admins', 'update_admins'] as $key) {
            $permission = Permission::where('key', $key)->firstOrFail();
            $role->permissions()->attach($permission);
        }
        $actor = AdminUser::factory()->create();
        $actor->roles()->attach($role);
        $superRole = $super->roles()->first();
        $this->actingAs($actor, 'admin')->post(route('admin.admins.store'), [
            'name' => 'New admin', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
            'status' => AdminUser::STATUS_ACTIVE, 'role_ids' => [$superRole->id],
        ])->assertForbidden();
        $this->assertDatabaseMissing('admin_users', ['email' => 'new@example.com']);
    }

    public function test_view_clients_exception_remains_unchanged(): void
    {
        $this->createSuperAdmin();
        $role = Role::create(['key' => 'view-only-clients', 'name' => 'View clients']);
        $role->permissions()->attach(Permission::where('key', 'view_clients')->firstOrFail());
        $actor = AdminUser::factory()->create();
        $actor->roles()->attach($role);
        $client = $this->createWorkspaceContext()['client'];
        $this->assertTrue((new ClientPolicy)->assignPlan($actor, $client));
        $this->assertTrue((new ClientPolicy)->impersonate($actor, $client));
    }
}
