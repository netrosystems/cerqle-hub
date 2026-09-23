<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep a copy of each connected account's profile picture.
 *
 * picture_url held the provider's own link, and Meta and TikTok hand out
 * signed CDN links that stop working on their own — Facebook and Instagram
 * about four days after they are issued, TikTok within hours. Every connected
 * Page's picture broke on that schedule. The image is now stored in the
 * app's own storage and served from there; picture_url keeps the provider's
 * link as the source it was copied from.
 *
 * Nullable, so existing rows keep showing their old link until a copy is
 * made; nothing needs backfilling for the page to keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_accounts', function (Blueprint $table) {
            $table->string('picture_path', 512)->nullable()->after('picture_url');
            $table->string('picture_disk', 32)->nullable()->after('picture_path');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_accounts', function (Blueprint $table) {
            $table->dropColumn(['picture_path', 'picture_disk']);
        });
    }
};
