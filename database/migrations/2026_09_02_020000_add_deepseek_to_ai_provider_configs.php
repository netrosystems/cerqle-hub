<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ai_provider_configs MODIFY provider ENUM('openai', 'anthropic', 'gemini', 'deepseek') NOT NULL");

            return;
        }

        Schema::table('ai_provider_configs', function (Blueprint $table): void {
            $table->enum('provider', ['openai', 'anthropic', 'gemini', 'deepseek'])->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('ai_provider_configs')->where('provider', 'deepseek')->delete();
            DB::statement("ALTER TABLE ai_provider_configs MODIFY provider ENUM('openai', 'anthropic', 'gemini') NOT NULL");

            return;
        }

        DB::table('ai_provider_configs')->where('provider', 'deepseek')->delete();
        Schema::table('ai_provider_configs', function (Blueprint $table): void {
            $table->enum('provider', ['openai', 'anthropic', 'gemini'])->change();
        });
    }
};
