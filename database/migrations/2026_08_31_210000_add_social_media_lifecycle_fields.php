<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->boolean('is_temporary')->default(false)->after('collection')->index();
            $table->timestamp('quota_released_at')->nullable()->after('is_temporary')->index();
            $table->timestamp('purge_after')->nullable()->after('quota_released_at')->index();
        });

        Schema::table('social_media_posts', function (Blueprint $table) {
            $table->json('platform_payloads')->nullable()->after('youtube_options');
            $table->timestamp('temporary_media_released_at')->nullable()->after('platform_payloads');
        });

        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'published', 'failed'])
                ->default('pending')
                ->change();
        });

        Schema::create('media_social_post', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('social_post_id')->constrained('social_media_posts')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['media_id', 'social_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_social_post');

        Schema::table('social_media_posts', function (Blueprint $table) {
            $table->dropColumn(['platform_payloads', 'temporary_media_released_at']);
        });

        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'published', 'failed'])
                ->default('pending')
                ->change();
        });

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['is_temporary', 'quota_released_at', 'purge_after']);
        });
    }
};
