<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_list_operations', function (Blueprint $table) {
            // Split the generic "skipped" bucket so the UI can show exactly why
            // a row did not end up in the list. All default to 0 so existing
            // imports continue to display the same total.
            $table->unsignedBigInteger('skipped_invalid_phone')->default(0)->after('skipped_existing_customer');
            $table->unsignedBigInteger('skipped_malformed_row')->default(0)->after('skipped_invalid_phone');
            $table->unsignedBigInteger('skipped_duplicate_in_file')->default(0)->after('skipped_malformed_row');
        });
    }

    public function down(): void
    {
        Schema::table('contact_list_operations', function (Blueprint $table) {
            $table->dropColumn(['skipped_invalid_phone', 'skipped_malformed_row', 'skipped_duplicate_in_file']);
        });
    }
};
