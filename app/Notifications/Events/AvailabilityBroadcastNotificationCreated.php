<?php

namespace App\Notifications\Events;

use App\Services\NotificationDeliveryPolicy;
use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

class AvailabilityBroadcastNotificationCreated extends BroadcastNotificationCreated
{
    /** @return array<Channel> */
    public function broadcastOn()
    {
        if (! app(NotificationDeliveryPolicy::class)->allows($this->notifiable, $this->notification, 'broadcast')) {
            return [];
        }

        return parent::broadcastOn();
    }

    /** @return array<string, mixed> */
    public function broadcastWith()
    {
        $data = parent::broadcastWith();
        $data['silent'] = ($data['silent'] ?? false)
            || app(NotificationDeliveryPolicy::class)->silent($this->notifiable, $this->notification);

        return $data;
    }

    public function broadcastAs()
    {
        // Preserve Laravel's public wire event name for existing clients.
        return method_exists($this->notification, 'broadcastAs')
            ? $this->notification->broadcastAs()
            : BroadcastNotificationCreated::class;
    }
}
