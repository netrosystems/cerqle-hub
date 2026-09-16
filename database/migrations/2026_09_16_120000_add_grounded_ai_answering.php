<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_kb_generations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('kb_id');
            $table->string('status', 24)->default('building');
            $table->unsignedInteger('document_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('kb_id')->references('id')->on('ai_knowledge_bases')->cascadeOnDelete();
            $table->index(['kb_id', 'status']);
        });

        Schema::table('ai_knowledge_bases', function (Blueprint $table): void {
            $table->string('business_name', 160)->nullable()->after('name');
            $table->text('business_purpose')->nullable()->after('business_name');
            $table->text('target_audience')->nullable()->after('business_purpose');
            $table->unsignedBigInteger('active_generation_id')->nullable()->after('status');
            $table->unsignedBigInteger('pending_generation_id')->nullable()->after('active_generation_id');
        });

        Schema::table('ai_kb_chunks', function (Blueprint $table): void {
            $table->unsignedBigInteger('generation_id')->nullable()->after('kb_id');
            $table->string('source_title', 256)->nullable()->after('tokens');
            $table->string('source_url', 2048)->nullable()->after('source_title');
            $table->index(['kb_id', 'generation_id'], 'ai_kb_chunks_generation_idx');
        });

        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->string('answer_scope', 24)->default('business_only')->after('tone');
            $table->string('fallback_mode', 32)->default('clarify_then_handoff')->after('fallback_reply');
            $table->decimal('confidence_threshold', 4, 3)->default(0.720)->after('fallback_mode');
            $table->decimal('clarification_threshold', 4, 3)->default(0.470)->after('confidence_threshold');
        });

        // Existing bots keep their current broad behavior. Newly created bots use
        // the safer database default above.
        DB::table('ai_chatbots')->update(['answer_scope' => 'general']);

        DB::table('ai_knowledge_bases')->orderBy('id')->each(function (object $kb): void {
            $now = now();
            $generationId = DB::table('ai_kb_generations')->insertGetId([
                'kb_id' => $kb->id,
                'status' => 'active',
                'document_count' => DB::table('ai_kb_documents')->where('kb_id', $kb->id)->count(),
                'chunk_count' => DB::table('ai_kb_chunks')->where('kb_id', $kb->id)->count(),
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('ai_kb_chunks')->where('kb_id', $kb->id)->update(['generation_id' => $generationId]);
            DB::table('ai_knowledge_bases')->where('id', $kb->id)->update(['active_generation_id' => $generationId]);
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->dropColumn(['answer_scope', 'fallback_mode', 'confidence_threshold', 'clarification_threshold']);
        });

        Schema::table('ai_kb_chunks', function (Blueprint $table): void {
            $table->dropIndex('ai_kb_chunks_generation_idx');
            $table->dropColumn(['generation_id', 'source_title', 'source_url']);
        });

        Schema::table('ai_knowledge_bases', function (Blueprint $table): void {
            $table->dropColumn([
                'business_name', 'business_purpose', 'target_audience',
                'active_generation_id', 'pending_generation_id',
            ]);
        });

        Schema::dropIfExists('ai_kb_generations');
    }
};
