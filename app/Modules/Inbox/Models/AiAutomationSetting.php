<?php

namespace App\Modules\Inbox\Models;

use Illuminate\Database\Eloquent\Model;

class AiAutomationSetting extends Model
{
    protected $table = 'workspace_ai_automation_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekly_hours' => 'array', 'mailbox_ids' => 'array', 'activated_at' => 'immutable_datetime', 'revision' => 'integer'];
    }
}
