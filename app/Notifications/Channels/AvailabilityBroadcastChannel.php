<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\WorkspaceWorkNotification;
use App\Notifications\Events\AvailabilityBroadcastNotificationCreated;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AvailabilityBroadcastChannel extends BroadcastChannel
{
    /** @return array<mixed>|null */
    public function send($notifiable, Notification $notification)
    {
        if (! $notification instanceof WorkspaceWorkNotification) {
            return parent::send($notifiable, $notification);
        }

        if (! app(NotificationDeliveryPolicy::class)->allows($notifiable, $notification, 'broadcast')) {
            return null;
        }

        $message = $this->getData($notifiable, $notification);
        $data = is_array($message) ? $message : $message->data;
        $data['silent'] = ($data['silent'] ?? false)
            || app(NotificationDeliveryPolicy::class)->silent($notifiable, $notification);
        $event = new AvailabilityBroadcastNotificationCreated($notifiable, $notification, $data);

        if ($message instanceof BroadcastMessage) {
            $event->onConnection($message->connection)->onQueue($message->queue);
        }

        return $this->events->dispatch($event);
    }
}
