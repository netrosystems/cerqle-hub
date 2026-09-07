<?php

namespace App\Modules\Shared\Models;

use App\Services\ChannelPlanLimitService;
use App\Support\Concerns\EnforcesChannelPlanLimit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    use EnforcesChannelPlanLimit;

    protected function channelPlanLimitKey(): ?string
    {
        return in_array($this->channel, ChannelPlanLimitService::CHANNELS, true) ? 'messaging_channels' : null;
    }

    protected $fillable = [
        'workspace_id', 'channel', 'provider', 'credentials',
        'display_name', 'phone_number_id', 'business_account_id', 'status', 'meta_json',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'meta_json' => 'array',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
