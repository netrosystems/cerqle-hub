<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

class XPublishAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload', 'media_state', 'review_history'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'media_state' => 'encrypted:array', 'review_history' => 'encrypted:array', 'retry_at' => 'datetime'];
    }
}
