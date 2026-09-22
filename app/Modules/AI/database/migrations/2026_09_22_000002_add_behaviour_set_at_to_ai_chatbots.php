<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a client has actually chosen how the bot answers.
 *
 * Without it the setup step looks finished the moment the bot is created,
 * because answer_scope carries a default. A tick nobody earned tells the client
 * nothing and hides the one decision that changes what the bot will say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->timestamp('behaviour_set_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->dropColumn('behaviour_set_at');
        });
    }
};
