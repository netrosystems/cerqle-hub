<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/** One answered turn, recorded for operators. Never shown to a customer. */
class AiAnswerDiagnostic extends Model
{
    protected $table = 'ai_answer_diagnostics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'intent_score' => 'float',
            'best_score' => 'float',
            'passages_used' => 'integer',
            'passages_dropped' => 'integer',
            'context_chars' => 'integer',
            'translated_query' => 'boolean',
            'phrase_fallback' => 'boolean',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }
}
