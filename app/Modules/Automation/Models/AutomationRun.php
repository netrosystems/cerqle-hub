<?php

namespace App\Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $automation = $run->automation;
            if ($automation && ! $run->workflow_snapshot) {
                $run->workflow_snapshot = $automation->only(['nodes', 'edges', 'trigger_type', 'trigger_config', 'updated_at']);
            }
            $run->conversation_id ??= $run->context['conversation_id'] ?? null;
            $run->channel_account_id ??= $run->context['channel_account_id'] ?? null;
            $run->trigger_message_id ??= $run->context['message_id'] ?? null;
        });
    }

    protected $table = 'automation_runs';

    protected $fillable = ['automation_id', 'contact_id', 'status', 'context', 'current_node_id', 'resume_node_id', 'error', 'started_at', 'completed_at', 'conversation_id', 'channel_account_id', 'trigger_message_id', 'workflow_snapshot', 'wake_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'workflow_snapshot' => 'array',
            'wake_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return HasMany<AutomationRunLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(AutomationRunLog::class, 'run_id');
    }
}
