<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'webchat_last_seen_at')) {
                $table->timestamp('webchat_last_seen_at')
                    ->nullable()
                    ->after('last_inbound_at')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'webchat_last_seen_at')) {
                $table->dropIndex(['webchat_last_seen_at']);
                $table->dropColumn('webchat_last_seen_at');
            }
        });
    }
};
