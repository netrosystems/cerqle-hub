<?php

namespace App\Modules\Inbox\Models;

use App\Models\Workspace;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetPushSubscription extends Model
{
    protected $fillable = [
        'workspace_id',
        'chat_widget_id',
        'conversation_id',
        'visitor_id',
        'onesignal_subscription_id',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(ChatWidget::class, 'chat_widget_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
