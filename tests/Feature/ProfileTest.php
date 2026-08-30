<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->get('/app/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->from(route('client.profile.edit'))
            ->patch('/app/profile', [
                'name' => 'Test User',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_client_cannot_change_login_email_from_profile(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $originalEmail = $user->email;

        $response = $this
            ->actingAs($user)
            ->from(route('client.profile.edit'))
            ->patch('/app/profile', [
                'name' => 'Test User',
                'email' => 'changed@example.com',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('client.profile.edit'));

        $this->assertSame($originalEmail, $user->refresh()->email);
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->delete('/app/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->from(route('client.profile.edit'))
            ->delete('/app/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('client.profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
