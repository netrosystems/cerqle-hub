<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->string('ai_mode', 16)->default('off');
            $table->string('ai_timezone', 64)->nullable();
            $table->json('ai_weekly_hours')->nullable();
            $table->unsignedInteger('ai_revision')->default(1);
        });
        DB::table('chat_widgets')->where('ai_enabled', true)->update(['ai_mode' => 'permanent']);
    }

    public function down(): void
    {
        Schema::table('chat_widgets', fn (Blueprint $table) => $table->dropColumn(['ai_mode', 'ai_timezone', 'ai_weekly_hours', 'ai_revision']));
    }
};
