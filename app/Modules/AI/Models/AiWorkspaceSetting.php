<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiWorkspaceSetting extends Model
{
    protected $fillable = ['workspace_id', 'provider_mode'];
}
