<?php

namespace App\Modules\Broadcasting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignStep extends Model
{
    protected $fillable = [
        'campaign_id', 'position', 'name', 'recipient_limit',
        'delay_after_previous_seconds', 'rate_per_second', 'status',
        'scheduled_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'recipient_limit' => 'integer',
            'delay_after_previous_seconds' => 'integer',
            'rate_per_second' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
