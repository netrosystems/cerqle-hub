<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKbGeneration extends Model
{
    protected $table = 'ai_kb_generations';

    protected $fillable = [
        'kb_id', 'status', 'document_count', 'chunk_count', 'error_message', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'document_count' => 'integer',
            'chunk_count' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiKnowledgeBase, $this> */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'kb_id');
    }

    /** @return HasMany<AiKbChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(AiKbChunk::class, 'generation_id');
    }
}
