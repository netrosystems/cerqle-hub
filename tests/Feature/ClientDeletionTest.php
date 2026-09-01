<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ClientDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_client_releases_email_and_anonymizes_retained_history(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $email = $user->email;
        $plan = Plan::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now(),
            'gateway' => 'manual',
        ]);
        $payment = PaymentTransaction::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'gateway' => 'test',
            'gateway_transaction_id' => 'retained-reference',
            'amount_cents' => 1000,
            'currency_code' => 'USD',
            'status' => 'paid',
            'payload' => ['email' => $email],
        ]);
        $audit = AuditLog::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action' => 'test.action',
            'meta' => ['email' => $email],
            'ip' => '127.0.0.1',
        ]);

        app(ClientDeletionService::class)->delete($client);

        $this->assertDatabaseMissing('users', ['email' => $email]);
        User::factory()->create(['email' => $email]);
        $this->assertNull($payment->fresh()->user_id);
        $this->assertNull($payment->fresh()->payload);
        $this->assertNull($audit->fresh()->client_id);
        $this->assertNull($audit->fresh()->meta);
    }

    public function test_super_admin_must_enter_exact_client_name_before_permanent_deletion(): void
    {
        $admin = $this->createSuperAdmin();
        ['client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.clients.destroy', $client), ['confirmation' => 'wrong'])
            ->assertSessionHasErrors('confirmation');
        $this->assertDatabaseHas('clients', ['id' => $client->id]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.clients.destroy', $client), ['confirmation' => $client->name])
            ->assertRedirect(route('admin.clients.index'));
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }
}
