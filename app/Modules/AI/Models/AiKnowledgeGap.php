<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/** A question the bot could not answer, and how often customers ask it. */
class AiKnowledgeGap extends Model
{
    protected $table = 'ai_knowledge_gaps';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'best_score' => 'float',
            'last_seen_at' => 'datetime',
        ];
    }
}
