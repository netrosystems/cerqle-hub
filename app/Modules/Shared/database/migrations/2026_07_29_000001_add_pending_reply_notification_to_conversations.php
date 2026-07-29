<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('pending_reply_notified_at')
                ->nullable()
                ->after('last_inbound_at')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['pending_reply_notified_at']);
            $table->dropColumn('pending_reply_notified_at');
        });
    }
};
