<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('sms_provider', 32)->nullable()->after('whatsapp_phone_number_id');
            $table->index(['workspace_id', 'sms_provider']);
        });

        Schema::table('sms_provider_configs', function (Blueprint $table) {
            // This is a customer-confirmed gateway ceiling, not an estimate.
            // A conservative provider default is used until it is configured.
            $table->unsignedSmallInteger('throughput_tps')->nullable()->after('sender_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'sms_provider']);
            $table->dropColumn('sms_provider');
        });

        Schema::table('sms_provider_configs', function (Blueprint $table) {
            $table->dropColumn('throughput_tps');
        });
    }
};
