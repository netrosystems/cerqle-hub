<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKbChunk extends Model
{
    protected $table = 'ai_kb_chunks';

    protected $fillable = [
        'kb_id', 'generation_id', 'document_id', 'ord', 'content', 'tokens', 'embedding',
        'source_title', 'source_url',
    ];

    protected function casts(): array
    {
        return ['tokens' => 'integer', 'ord' => 'integer', 'generation_id' => 'integer'];
    }

    /** @return BelongsTo<AiKbDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AiKbDocument::class, 'document_id');
    }

    /** @return BelongsTo<AiKbGeneration, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiKbGeneration::class, 'generation_id');
    }
}
