<?php

namespace App\Models;

use App\Modules\Shared\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalNote extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'mentioned_user_ids',
    ];

    protected $casts = [
        'mentioned_user_ids' => 'array',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
