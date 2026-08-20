<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_log_in_and_receive_a_mobile_token(): void
    {
        $user = User::factory()->create([
            'email' => 'agent@example.com',
            'status' => User::STATUS_ACTIVE,
        ]);

        $response = $this->fromIp('203.0.113.10')->postJson('/api/v1/auth/login', [
            'email' => ' Agent@Example.com ',
            'password' => 'password',
            'device_name' => 'QA iPhone',
            'device_id' => 'onesignal-subscription-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'QA iPhone',
        ]);
        $this->assertDatabaseHas('user_push_tokens', [
            'user_id' => $user->id,
            'provider' => 'onesignal',
            'token' => 'onesignal-subscription-123',
            'device_name' => 'QA iPhone',
            'revoked_at' => null,
        ]);
    }

    public function test_failed_credentials_are_limited_by_email_and_ip(): void
    {
        User::factory()->create([
            'email' => 'agent@example.com',
            'status' => User::STATUS_ACTIVE,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->fromIp('203.0.113.11')->postJson('/api/v1/auth/login', [
                'email' => 'agent@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->fromIp('203.0.113.11')->postJson('/api/v1/auth/login', [
            'email' => 'agent@example.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'login_rate_limited')
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_successful_login_clears_previous_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'agent@example.com',
            'status' => User::STATUS_ACTIVE,
        ]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->fromIp('203.0.113.12')->postJson('/api/v1/auth/login', [
                'email' => 'agent@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->fromIp('203.0.113.12')->postJson('/api/v1/auth/login', [
            'email' => 'agent@example.com',
            'password' => 'password',
        ])->assertOk();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->fromIp('203.0.113.12')->postJson('/api/v1/auth/login', [
                'email' => 'agent@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }
    }

    public function test_different_email_does_not_share_the_failed_login_bucket(): void
    {
        User::factory()->create([
            'email' => 'second@example.com',
            'status' => User::STATUS_ACTIVE,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->fromIp('203.0.113.13')->postJson('/api/v1/auth/login', [
                'email' => 'first@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->fromIp('203.0.113.13')->postJson('/api/v1/auth/login', [
            'email' => 'second@example.com',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_ip_abuse_guard_returns_structured_rate_limit_response(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->fromIp('203.0.113.14')->postJson('/api/v1/auth/login', [])
                ->assertUnprocessable();
        }

        $this->fromIp('203.0.113.14')->postJson('/api/v1/auth/login', [])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'login_rate_limited')
            ->assertJsonStructure(['message', 'retry_after']);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }
}
