<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A new widget should arrive in Cerqle's own colour, not an orange that appears
 * nowhere else in the product. Clients change it from there as before.
 *
 * Existing widgets are left exactly as they are: whether a client chose that
 * orange or simply never touched it, it is on their live site and changing it
 * under them is not this migration's business.
 */
return new class extends Migration
{
    private const OLD = '#ff762e';

    public function up(): void
    {
        $this->setDefault((string) config('saas.branding.primary_color', '#8F5FA7'));
    }

    public function down(): void
    {
        $this->setDefault(self::OLD);
    }

    private function setDefault(string $colour): void
    {
        if (! Schema::hasTable('chat_widgets') || ! Schema::hasColumn('chat_widgets', 'primary_color')) {
            return;
        }

        // sqlite cannot alter a column default in place, and the test database
        // is built from the create migration anyway, so it is skipped there.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE chat_widgets ALTER COLUMN primary_color SET DEFAULT '".$colour."'");
    }
};
