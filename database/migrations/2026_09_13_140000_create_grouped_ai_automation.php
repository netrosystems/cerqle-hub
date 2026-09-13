<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_automation_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('group', 20);
            $table->string('mode', 20)->default('off');
            $table->unsignedBigInteger('chatbot_id')->nullable();
            $table->string('timezone')->default('UTC');
            $table->json('weekly_hours');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'group']);
        });
        Schema::create('inbound_reply_ownerships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedBigInteger('channel_account_id');
            $table->unsignedBigInteger('message_id')->unique();
            $table->string('owner', 20)->default('routing');
            $table->string('status', 30)->default('claimed');
            $table->unsignedBigInteger('outbound_message_id')->nullable();
            $table->unsignedInteger('settings_revision')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_reply_ownerships');
        Schema::dropIfExists('workspace_ai_automation_settings');
    }
};
