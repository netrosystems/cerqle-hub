<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'agree_terms' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('client.dashboard', absolute: false));
        $this->assertNull(auth()->user()->email_verified_at);
    }

    public function test_new_unverified_user_can_open_dashboard_but_not_operational_features(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'client']);

        $this->actingAs($user)->get(route('client.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('client.social.posts.index'))
            ->assertRedirect(route('client.dashboard'));
    }
}
