<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->string('connection_mode', 24)->default('cloud_api');
            $table->json('coexistence_meta')->nullable();
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->string('origin', 32)->default('live');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['connection_mode', 'coexistence_meta']);
        });
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('origin'));
    }
};
