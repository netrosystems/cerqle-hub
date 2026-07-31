<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_campaign_only')->default(false)->after('source');
            $table->index(['workspace_id', 'is_campaign_only'], 'contacts_workspace_directory_idx');
        });

        DB::table('contacts')
            ->whereIn('source', ['contact_list_csv', 'campaign_csv'])
            ->update(['is_campaign_only' => true]);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_workspace_directory_idx');
            $table->dropColumn('is_campaign_only');
        });
    }
};
