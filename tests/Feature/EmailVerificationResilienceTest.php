<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Mail\MailService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EmailVerificationResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_rejection_does_not_escape_email_verification_dispatch(): void
    {
        Notification::fake();
        $mail = Mockery::mock(MailService::class);
        $mail->shouldReceive('sendWithTemplate')->once()->andThrow(new RuntimeException('550 No Such User Here'));
        $this->app->instance(MailService::class, $mail);
        $user = User::factory()->create(['email_verified_at' => null]);

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_team_member_creation_succeeds_when_all_verification_delivery_methods_fail(): void
    {
        $ctx = $this->createWorkspaceContext();
        $mail = Mockery::mock(MailService::class);
        $mail->shouldReceive('sendWithTemplate')->andThrow(new RuntimeException('SMTP recipient rejected'));
        $this->app->instance(MailService::class, $mail);

        // Let notification delivery execute so the model's fallback catch is
        // exercised as it is in production with a rejected recipient mailbox.
        $this->actingAs($ctx['user'])
            ->post(route('client.team.store'), [
                'name' => 'Mail Failure Member',
                'email' => 'mail-failure@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => User::STATUS_ACTIVE,
                'workspace_assignments' => [[
                    'workspace_id' => $ctx['workspace']->id,
                    'role' => 'staff',
                ]],
            ])
            ->assertRedirect(route('client.team.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'mail-failure@example.test',
            'client_id' => $ctx['client']->id,
        ]);
    }
}
