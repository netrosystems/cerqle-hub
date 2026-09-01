<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiCreditAdjustment extends Model
{
    protected $fillable = ['period_id', 'admin_user_id', 'credits', 'reason'];
}
