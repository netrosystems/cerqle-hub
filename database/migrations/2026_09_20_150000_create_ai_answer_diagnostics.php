<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A machine-readable twin for the existing free-text `reason`. Every
        // routing skip writes one, so "why did the bot not reply?" stops being
        // a support investigation.
        Schema::table('inbound_reply_ownerships', function (Blueprint $table): void {
            $table->string('reason_code', 48)->nullable()->after('reason');
            $table->index(['workspace_id', 'reason_code'], 'inbound_reply_reason_idx');
        });

        // One row per answered turn, including the ones that cost nothing.
        // Named for answers rather than retrieval because greeting, starter and
        // choice turns never reach the Knowledge Base.
        Schema::create('ai_answer_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('kb_id')->nullable();
            $table->unsignedBigInteger('chatbot_id')->nullable();
            $table->unsignedBigInteger('generation_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('channel', 32)->nullable();
            $table->string('decision', 16)->default('answer');
            $table->string('answer_origin', 32)->default('general');
            $table->string('intent', 32)->nullable();
            $table->decimal('intent_score', 5, 4)->nullable();
            $table->string('intent_method', 16)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('cache_source', 16)->nullable();
            $table->decimal('best_score', 6, 4)->default(0);
            $table->unsignedSmallInteger('passages_used')->default(0);
            $table->unsignedSmallInteger('passages_dropped')->default(0);
            $table->unsignedInteger('context_chars')->default(0);
            $table->boolean('translated_query')->default(false);
            $table->boolean('phrase_fallback')->default(false);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->string('credit_result', 16)->nullable();
            $table->string('reason_code', 48)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'created_at'], 'ai_diag_ws_created_idx');
            $table->index(['workspace_id', 'decision'], 'ai_diag_ws_decision_idx');
            $table->index(['workspace_id', 'chatbot_id', 'created_at'], 'ai_diag_ws_bot_idx');
        });

        // The questions the bot could not answer, with how often they are asked.
        // This is the list that tells a client which page to write next.
        Schema::create('ai_knowledge_gaps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('kb_id');
            $table->unsignedBigInteger('chatbot_id')->nullable();
            $table->char('fingerprint', 40);
            $table->string('sample_question', 512);
            $table->string('language', 16)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->decimal('best_score', 6, 4)->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->unique(['workspace_id', 'kb_id', 'fingerprint'], 'ai_kb_gaps_unique');
            $table->index(['workspace_id', 'status', 'occurrences'], 'ai_kb_gaps_ws_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_gaps');
        Schema::dropIfExists('ai_answer_diagnostics');

        Schema::table('inbound_reply_ownerships', function (Blueprint $table): void {
            $table->dropIndex('inbound_reply_reason_idx');
            $table->dropColumn('reason_code');
        });
    }
};
