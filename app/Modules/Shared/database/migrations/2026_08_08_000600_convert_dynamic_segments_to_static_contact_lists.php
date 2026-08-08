<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic lists were materialised into segment_contact when created. Keep
     * that membership, discard their rules, and make every legacy list behave
     * exactly like a static contact list going forward.
     */
    public function up(): void
    {
        if (! Schema::hasTable('segments')) {
            return;
        }

        $changes = [
            'type' => 'static',
            'updated_at' => now(),
        ];

        // Existing installations retain the legacy column for a non-breaking
        // upgrade, but its values are discarded. New installations no longer
        // create the column at all.
        if (Schema::hasColumn('segments', 'rules_json')) {
            $changes['rules_json'] = null;
        }

        DB::table('segments')->where('type', 'dynamic')->update($changes);
    }

    public function down(): void
    {
        // The former rules were intentionally removed and cannot be restored.
    }
};
