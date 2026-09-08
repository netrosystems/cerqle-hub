<?php

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappEchoReceipt extends Model
{
    protected $fillable = ['workspace_id', 'phone_id', 'event_key', 'payload', 'status'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }
}
