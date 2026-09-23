<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let AI automatic replies apply to chosen mailboxes rather than every mailbox
 * in the workspace.
 *
 * NULL means every mailbox, which is what the setting already meant, so every
 * existing row keeps behaving exactly as it does today without a backfill. An
 * empty array is a different thing — deliberately no mailboxes — and stays
 * distinguishable from "not chosen yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_ai_automation_settings', function (Blueprint $table) {
            $table->json('mailbox_ids')->nullable()->after('chatbot_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_ai_automation_settings', function (Blueprint $table) {
            $table->dropColumn('mailbox_ids');
        });
    }
};
