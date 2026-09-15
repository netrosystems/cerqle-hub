<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_accounts', function (Blueprint $table): void {
            $table->timestamp('disconnected_at')->nullable()->index();
        });
        Schema::create('x_publish_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('social_media_posts')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_media_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('status')->default('pending');
            $table->text('payload');
            $table->text('media_state')->nullable();
            $table->text('review_history')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_publish_attempts');
        Schema::table('social_media_accounts', function (Blueprint $table): void {
            $table->dropColumn('disconnected_at');
        });
    }
};
