<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAvailability extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['weekly_hours' => 'array', 'revision' => 'integer'];
}
