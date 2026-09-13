<?php

namespace App\Providers;

use App\Notifications\Channels\AvailabilityBroadcastChannel;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NotificationDeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BroadcastChannel::class, AvailabilityBroadcastChannel::class);
    }

    public function boot(): void
    {
        // Closures live outside app/Listeners to avoid automatic discovery and
        // duplicate registration. These also cover synchronous notifyNow sends.
        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            return app(NotificationDeliveryPolicy::class)->allows($event->notifiable, $event->notification, $event->channel)
                ? null : false;
        });

        Event::listen(MessageSending::class, function (MessageSending $event) {
            $notification = $event->data['__cerqle_work_notification'] ?? null;
            $recipient = $event->data['__cerqle_work_recipient'] ?? null;
            if (! $notification instanceof Notification || ! is_object($recipient)) {
                return null;
            }

            return app(NotificationDeliveryPolicy::class)->allows($recipient, $notification, 'mail')
                ? null : false;
        });
    }
}
