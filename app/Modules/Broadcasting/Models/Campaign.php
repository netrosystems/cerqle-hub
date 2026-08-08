<?php

namespace App\Modules\Broadcasting\Models;

use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $channel
 * @property string $audience_type
 * @property string|null $audience_ref
 * @property array<string, mixed>|null $template_ref
 * @property array<string, mixed>|null $payload_json
 * @property Carbon|null $schedule_at
 * @property string|null $timezone
 * @property string $status
 * @property array<string, mixed>|null $totals_json
 * @property int|null $created_by
 */
class Campaign extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return CampaignFactory::new();
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

    protected $fillable = [
        'workspace_id', 'name', 'channel', 'whatsapp_phone_number_id', 'sms_provider', 'audience_type', 'audience_ref',
        'template_ref', 'payload_json', 'schedule_at', 'timezone', 'status', 'totals_json', 'created_by',
        'provider_key', 'estimated_recipients', 'prepared_recipients', 'preparation_cursor',
        'preparation_offset', 'audience_cutoff_id', 'is_large', 'pause_reason',
        'audience_prepared_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'template_ref' => 'array',
            'payload_json' => 'array',
            'totals_json' => 'array',
            'schedule_at' => 'datetime',
            'estimated_recipients' => 'integer',
            'prepared_recipients' => 'integer',
            'preparation_cursor' => 'integer',
            'preparation_offset' => 'integer',
            'audience_cutoff_id' => 'integer',
            'is_large' => 'boolean',
            'audience_prepared_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('position');
    }

    public function updateTotals(): void
    {
        $counts = $this->recipients()
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        // Count clicks independently — a recipient can click without their status
        // changing to a distinct "clicked" value (we keep status = 'read').
        $clicked = $this->recipients()
            ->whereNotNull('clicked_at')
            ->count();

        // Count unsubscribes independently.
        $unsubscribed = $this->recipients()
            ->whereNotNull('opted_out_at')
            ->count();

        $sent = $counts['sent'] ?? 0;
        $delivered = $counts['delivered'] ?? 0;
        $read = $counts['read'] ?? 0;

        if ($this->channel === 'sms') {
            // Older SMS recipients may still be stored as sent. They represent
            // the same successful gateway acknowledgement as Delivered.
            $delivered += $sent + $read;
            $sent = 0;
            $read = 0;
        }

        $this->update([
            'totals_json' => [
                'total' => array_sum($counts),
                'queued' => ($counts['queued'] ?? 0)
                    + ($counts['dispatching'] ?? 0)
                    + ($counts['sending'] ?? 0),
                'retrying' => $counts['retrying'] ?? 0,
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'failed' => $counts['failed'] ?? 0,
                'clicked' => $clicked,
                'unsubscribed' => $unsubscribed,
            ],
        ]);
    }
}
