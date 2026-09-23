<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->string('sdk_widget_key', 64)->nullable()->unique()->after('widget_key');
            $table->boolean('sdk_enabled')->default(true)->after('enabled');
        });

        DB::table('chat_widgets')
            ->whereNull('sdk_widget_key')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($widgets): void {
                foreach ($widgets as $widget) {
                    DB::table('chat_widgets')
                        ->where('id', $widget->id)
                        ->update(['sdk_widget_key' => $this->uniqueSdkKey()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->dropUnique(['sdk_widget_key']);
            $table->dropColumn(['sdk_widget_key', 'sdk_enabled']);
        });
    }

    private function uniqueSdkKey(): string
    {
        do {
            $key = Str::random(32);
            $exists = DB::table('chat_widgets')
                ->where('widget_key', $key)
                ->orWhere('sdk_widget_key', $key)
                ->exists();
        } while ($exists);

        return $key;
    }
};
