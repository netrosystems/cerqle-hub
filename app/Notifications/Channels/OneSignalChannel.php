<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\OneSignalService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;

class OneSignalChannel
{
    public function __construct(private OneSignalService $service) {}

    public function isConfigured(): bool
    {
        return $this->service->isConfigured();
    }

    public function send(object $notifiable, Notification $notification): void
    {
        // Cerqle's OneSignal app belongs to client-team users. Super Admin
        // accounts are intentionally configuration-only and never receive push.
        if (! $notifiable instanceof User) {
            return;
        }

        if (! method_exists($notification, 'toOneSignal')) {
            return;
        }

        if (! $this->service->isConfigured()) {
            return;
        }

        $data = $notification->toOneSignal($notifiable);
        $title = $data['title'] ?? 'Notification';
        $body = $data['body'] ?? '';
        $url = $data['url'] ?? null;
        $conversationId = $data['conversation_id'] ?? null;

        $this->service->sendToExternalId(
            'user:'.$notifiable->id,
            $title,
            $body,
            $url,
            $conversationId,
            Arr::except($data, ['title', 'body', 'url']),
        );
    }
}
