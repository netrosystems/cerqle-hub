<?php

namespace App\Modules\AI\Models;

use Database\Factories\AiKnowledgeBaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiKnowledgeBase extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return AiKnowledgeBaseFactory::new();
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

    protected $table = 'ai_knowledge_bases';

    protected $fillable = [
        'workspace_id', 'name', 'business_name', 'business_purpose', 'target_audience',
        'embedding_model', 'dimensions', 'status', 'active_generation_id', 'pending_generation_id',
    ];

    protected $appends = ['profile_complete'];

    protected function casts(): array
    {
        return [
            'active_generation_id' => 'integer',
            'pending_generation_id' => 'integer',
        ];
    }

    public function getProfileCompleteAttribute(): bool
    {
        return filled($this->business_name)
            && filled($this->business_purpose)
            && filled($this->target_audience);
    }

    /** @return HasMany<AiKbDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(AiKbDocument::class, 'kb_id');
    }

    /** @return HasMany<AiChatbot, $this> */
    public function chatbots(): HasMany
    {
        return $this->hasMany(AiChatbot::class, 'ai_kb_id');
    }

    /** @return HasMany<AiKbGeneration, $this> */
    public function generations(): HasMany
    {
        return $this->hasMany(AiKbGeneration::class, 'kb_id');
    }

    /** @return BelongsTo<AiKbGeneration, $this> */
    public function activeGeneration(): BelongsTo
    {
        return $this->belongsTo(AiKbGeneration::class, 'active_generation_id');
    }
}
