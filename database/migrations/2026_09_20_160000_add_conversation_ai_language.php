<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer's language is remembered per conversation so later
        // deterministic turns are served from cache instead of being guessed at
        // again. Nullable everywhere: an unknown language is a normal state, not
        // an error, and the bot answers regardless.
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('ai_language', 16)->nullable()->after('status');
            $table->string('ai_language_source', 16)->nullable()->after('ai_language');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['ai_language', 'ai_language_source']);
        });
    }
};
