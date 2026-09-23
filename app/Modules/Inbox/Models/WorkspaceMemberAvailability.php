<?php

namespace App\Modules\Inbox\Models;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMemberAvailability extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'enabled', 'timezone', 'schedule_json'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'schedule_json' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
