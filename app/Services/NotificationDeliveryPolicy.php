<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\WorkspaceWorkNotification;
use Illuminate\Notifications\Notification;

class NotificationDeliveryPolicy
{
    public function allows(object $recipient, Notification $notification, string $channel): bool
    {
        // Only explicitly opted-in work notifications are affected. Account,
        // security and billing notifications must remain deliverable.
        if (! $notification instanceof WorkspaceWorkNotification) {
            return true;
        }

        $workspaceId = $notification->notificationWorkspaceId();
        if ($workspaceId === null && $notification->allowsUnscopedDelivery()) {
            return true;
        }

        if (! $recipient instanceof User || ! $workspaceId) {
            return false;
        }

        $user = User::find($recipient->id);
        if (! $user || ! Workspace::find($workspaceId)?->isAccessibleBy($user)) {
            return false;
        }

        // Availability never erases history or prevents realtime synchronization.
        if ($channel === 'database') {
            return true;
        }

        if (! $user->isActive()) {
            return false;
        }

        $preferenceChannel = match ($channel) {
            OneSignalChannel::class => 'one_signal',
            WebPushChannel::class => 'web_push',
            default => $channel,
        };
        $enabled = NotificationPreference::where('user_id', $user->id)
            ->where('event', $notification->notificationPreferenceEvent())
            ->where('channel', $preferenceChannel)
            ->value('enabled');
        if ($enabled !== null && ! $enabled) {
            return false;
        }

        if ($channel === 'broadcast') {
            return true;
        }

        return $notification->initialAvailability($user)
            && app(NotificationAvailability::class)->available($user, $workspaceId);
    }

    public function silent(object $recipient, Notification $notification): bool
    {
        if (! $notification instanceof WorkspaceWorkNotification) {
            return false;
        }

        $workspaceId = $notification->notificationWorkspaceId();
        if ($workspaceId === null && $notification->allowsUnscopedDelivery()) {
            return false;
        }

        if (! $recipient instanceof User || ! $workspaceId) {
            return true;
        }

        $user = User::find($recipient->id);

        return ! $user || ! $notification->initialAvailability($user)
            || ! app(NotificationAvailability::class)->available($user, $workspaceId);
    }
}
