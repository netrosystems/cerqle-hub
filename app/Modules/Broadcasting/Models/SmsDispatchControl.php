<?php

namespace App\Modules\Broadcasting\Models;

use Illuminate\Database\Eloquent\Model;

class SmsDispatchControl extends Model
{
    protected $table = 'sms_dispatch_controls';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    // Throughput enforcement depends on sub-second precision. Laravel's
    // default model date format drops microseconds even when the database
    // column supports them.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'key', 'active_campaign_id', 'next_slot_at',
        'systemic_failure_streak', 'heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'active_campaign_id' => 'integer',
            'next_slot_at' => 'datetime',
            'systemic_failure_streak' => 'integer',
            'heartbeat_at' => 'datetime',
        ];
    }
}
