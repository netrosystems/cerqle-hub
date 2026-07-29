<?php

namespace App\Modules\Broadcasting\Models;

use App\Modules\Shared\Models\Contact;
use Database\Factories\CampaignRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return CampaignRecipientFactory::new();
    }

    protected $fillable = [
        'campaign_id', 'campaign_step_id', 'contact_id', 'status', 'attempts',
        'next_attempt_at', 'claimed_at', 'provider_message_id', 'tracking_token', 'unsubscribe_token',
        'sent_at', 'delivered_at', 'read_at', 'clicked_at', 'opted_out_at', 'failed_reason',
        'failure_class', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'clicked_at' => 'datetime',
            'opted_out_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'campaign_step_id');
    }
}
