<?php

namespace App\Modules\AI\Models;

use Database\Factories\AiChatbotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** @property-read AiKnowledgeBase|null $knowledgeBase */
class AiChatbot extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return AiChatbotFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $table = 'ai_chatbots';

    protected $fillable = [
        'workspace_id', 'name', 'ai_kb_id', 'system_prompt', 'tone', 'answer_scope',
        'max_context_chunks', 'fallback_reply', 'fallback_mode', 'confidence_threshold',
        'clarification_threshold', 'channels', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'enabled' => 'boolean',
            'max_context_chunks' => 'integer',
            'confidence_threshold' => 'float',
            'clarification_threshold' => 'float',
        ];
    }

    /** @return BelongsTo<AiKnowledgeBase, $this> */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'ai_kb_id');
    }

    /** @return HasMany<AiRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'chatbot_id');
    }
}
