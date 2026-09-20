<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table): void {
            $table->boolean('authoritative')->default(false)->after('status');
            $table->unsignedTinyInteger('priority')->default(50)->after('authoritative');
            $table->index(['kb_id', 'authoritative', 'priority'], 'ai_kb_docs_authority_idx');
        });

        // Deliberately no backfill. With these defaults the authority weight is
        // zero for every existing document, so enabling the retrieval flag on
        // content nobody has curated cannot silently reorder answers.
    }

    public function down(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table): void {
            $table->dropIndex('ai_kb_docs_authority_idx');
            $table->dropColumn(['authoritative', 'priority']);
        });
    }
};
