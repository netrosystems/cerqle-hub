<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // VARCHAR keeps the state machine extensible across MySQL and SQLite.
            $table->string('status', 32)->default('draft')->change();
            $table->string('provider_key', 64)->nullable()->index()->after('status');
            $table->unsignedBigInteger('estimated_recipients')->default(0)->after('provider_key');
            $table->unsignedBigInteger('prepared_recipients')->default(0)->after('estimated_recipients');
            $table->unsignedBigInteger('preparation_cursor')->default(0)->after('prepared_recipients');
            $table->unsignedBigInteger('preparation_offset')->default(0)->after('preparation_cursor');
            $table->unsignedBigInteger('audience_cutoff_id')->nullable()->after('preparation_offset');
            $table->timestamp('audience_prepared_at')->nullable()->after('audience_cutoff_id');
            $table->boolean('is_large')->default(false)->index()->after('audience_prepared_at');
            $table->string('pause_reason', 512)->nullable()->after('is_large');
            $table->timestamp('started_at')->nullable()->after('pause_reason');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });

        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedSmallInteger('position');
            $table->string('name', 80);
            $table->unsignedBigInteger('recipient_limit')->nullable();
            $table->unsignedInteger('delay_after_previous_seconds')->default(0);
            $table->unsignedTinyInteger('rate_per_second')->default(5);
            $table->string('status', 24)->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'position']);
            $table->index(['campaign_id', 'status']);
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->string('status', 24)->default('queued')->change();
            $table->unsignedBigInteger('campaign_step_id')->nullable()->after('campaign_id');
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->timestamp('next_attempt_at', 6)->nullable()->after('attempts');
            $table->timestamp('claimed_at', 6)->nullable()->after('next_attempt_at');
            $table->string('failure_class', 32)->nullable()->after('failed_reason');
            $table->uuid('idempotency_key')->nullable()->after('failure_class');

            $table->index(['campaign_step_id', 'status', 'next_attempt_at'], 'campaign_recipient_step_due_idx');
            $table->index(['campaign_id', 'status', 'claimed_at'], 'campaign_recipient_claim_idx');
            $table->foreign('campaign_step_id')->references('id')->on('campaign_steps')->nullOnDelete();
        });

        Schema::create('sms_dispatch_controls', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->unsignedBigInteger('active_campaign_id')->nullable();
            $table->timestamp('next_slot_at', 6)->nullable();
            $table->unsignedInteger('systemic_failure_streak')->default(0);
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();

            $table->index('active_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_dispatch_controls');

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropForeign(['campaign_step_id']);
            $table->dropIndex('campaign_recipient_step_due_idx');
            $table->dropIndex('campaign_recipient_claim_idx');
            $table->dropColumn([
                'campaign_step_id', 'attempts', 'next_attempt_at', 'claimed_at',
                'failure_class', 'idempotency_key',
            ]);
            $table->enum('status', ['queued', 'sent', 'delivered', 'read', 'failed'])->default('queued')->change();
        });

        Schema::dropIfExists('campaign_steps');

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['provider_key']);
            $table->dropIndex(['is_large']);
            $table->dropColumn([
                'provider_key', 'estimated_recipients', 'prepared_recipients',
                'preparation_cursor', 'preparation_offset', 'audience_cutoff_id',
                'audience_prepared_at', 'is_large', 'pause_reason', 'started_at', 'completed_at',
            ]);
            $table->enum('status', ['draft', 'queued', 'sending', 'paused', 'completed', 'failed'])
                ->default('draft')
                ->change();
        });
    }
};
