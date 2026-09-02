<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workspace_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider_mode', 32)->default('managed');
            $table->timestamps();
        });

        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->string('test_status', 24)->nullable();
            $table->dateTime('tested_at')->nullable();
        });

        Schema::create('ai_credit_periods', function (Blueprint $table) {
            $table->id();
            $table->string('account_type', 24);
            $table->unsignedBigInteger('account_id');
            $table->string('subscription_type', 32)->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->unsignedInteger('allowance')->default(0);
            $table->unsignedInteger('used_credits')->default(0);
            $table->unsignedInteger('reserved_credits')->default(0);
            $table->timestamps();
            $table->unique(['account_type', 'account_id', 'period_start'], 'ai_credit_period_account_unique');
            $table->index(['account_type', 'account_id', 'period_end']);
        });

        Schema::create('ai_credit_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ai_credit_periods')->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature_key', 80);
            $table->string('rate_version', 24);
            $table->string('idempotency_key', 191)->unique();
            $table->string('provider_source', 24);
            $table->string('provider', 32)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('reserved_credits')->default(0);
            $table->unsignedInteger('charged_credits')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('cost_microusd')->default(0);
            $table->string('status', 24)->default('reserved');
            $table->string('error_code', 80)->nullable();
            $table->text('result_payload')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'feature_key', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('ai_credit_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ai_credit_periods')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->integer('credits');
            $table->string('reason', 500);
            $table->timestamps();
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('credit_usage_id')->nullable()->after('conversation_id')->constrained('ai_credit_usages')->nullOnDelete();
            $table->string('feature_key', 80)->nullable()->after('credit_usage_id');
            $table->string('provider_source', 24)->nullable()->after('feature_key');
            $table->string('provider', 32)->nullable()->after('provider_source');
            $table->unsignedBigInteger('cost_microusd')->default(0)->after('cost_cents');
        });

        // Existing customer-owned providers must keep their current behaviour.
        DB::table('ai_provider_configs')->where('enabled', true)->select('workspace_id')->distinct()->get()
            ->each(fn ($row) => DB::table('ai_workspace_settings')->updateOrInsert(
                ['workspace_id' => $row->workspace_id],
                ['provider_mode' => 'byok', 'created_at' => now(), 'updated_at' => now()],
            ));
        DB::table('ai_provider_configs')->where('enabled', true)->update([
            'test_status' => 'passed',
            'tested_at' => now(),
        ]);

        DB::table('plans')->orderBy('id')->get()->each(function ($plan): void {
            $limits = json_decode($plan->limits ?: '{}', true) ?: [];
            unset($limits['ai_tokens_per_month']);
            $limits['ai_credits_per_month'] = match ((int) $plan->monthly_price_cents) {
                0 => 100,
                2000 => 1000,
                4000 => 3000,
                15000 => 15000,
                default => 0,
            };
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_usage_id');
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['feature_key', 'provider_source', 'provider', 'cost_microusd']);
        });
        Schema::dropIfExists('ai_credit_usages');
        Schema::dropIfExists('ai_credit_adjustments');
        Schema::dropIfExists('ai_credit_periods');
        Schema::dropIfExists('ai_workspace_settings');
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->dropColumn(['test_status', 'tested_at']);
        });
    }
};
