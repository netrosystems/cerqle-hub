<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_list_operations', function (Blueprint $table) {
            // Rows in a CSV import that match a phone already belonging to a real
            // CRM customer. We never overwrite those customers, so we record
            // them separately from malformed/duplicate rows so the UI can show
            // a clear breakdown of why a row did not end up in the list.
            $table->unsignedBigInteger('skipped_existing_customer')->default(0)->after('skipped');
        });
    }

    public function down(): void
    {
        Schema::table('contact_list_operations', function (Blueprint $table) {
            $table->dropColumn('skipped_existing_customer');
        });
    }
};
