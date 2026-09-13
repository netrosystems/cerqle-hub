<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('channel_account_id')->nullable();
            $table->unsignedBigInteger('trigger_message_id')->nullable();
            $table->json('workflow_snapshot')->nullable();
            $table->timestamp('wake_at')->nullable();
            $table->unique(['automation_id', 'trigger_message_id']);
        });
        Schema::create('automation_step_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->string('node_id', 64);
            $table->unique(['run_id', 'node_id']);
            $table->timestamps();
        });
        Schema::create('automation_reply_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('automation_id');
            $table->unsignedBigInteger('message_id');
            $table->unique(['automation_id', 'message_id']);
            $table->timestamps();
        });
        DB::table('automation_runs')->whereIn('status', ['waiting', 'running'])->update(['status' => 'cancelled', 'error' => 'Legacy in-flight run has no pinned workflow; review before restarting.', 'completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_step_claims');
        Schema::dropIfExists('automation_reply_receipts');
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropUnique(['automation_id', 'trigger_message_id']);
            $table->dropColumn(['conversation_id', 'channel_account_id', 'trigger_message_id', 'workflow_snapshot', 'wake_at']);
        });
    }
};
