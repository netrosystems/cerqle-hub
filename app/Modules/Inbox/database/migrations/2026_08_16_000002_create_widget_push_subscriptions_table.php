<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_widget_id')->constrained('chat_widgets')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->string('onesignal_subscription_id');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'onesignal_subscription_id'], 'widget_push_conversation_subscription_unique');
            $table->index(['conversation_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_push_subscriptions');
    }
};
