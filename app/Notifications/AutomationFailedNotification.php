<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Modules\Automation\Models\AutomationRun;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Concerns\RespectsWorkspaceAvailability;
use App\Notifications\Contracts\WorkspaceWorkNotification;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AutomationFailedNotification extends Notification implements ShouldQueue, WorkspaceWorkNotification
{
    use Queueable;
    use RespectsWorkspaceAvailability;

    public function __construct(
        public readonly AutomationRun $run,
        public readonly string $errorMessage,
    ) {
        $this->captureNotificationSource($run->automation?->workspace_id);
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if ($this->isEnabled($notifiable, 'mail')) {
            $channels[] = 'mail';
        }

        if ($this->isEnabled($notifiable, 'one_signal')) {
            $channels[] = OneSignalChannel::class;
        }

        return $this->availabilityChannels($notifiable, $channels);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'automation_failed',
            'silent' => app(NotificationDeliveryPolicy::class)->silent($notifiable, $this),
            'workspace_id' => $this->notificationWorkspaceId(),
            'run_id' => $this->run->id,
            'automation' => $this->run->automation?->name,
            'error' => $this->errorMessage,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->availabilityBroadcastData($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->run->automation?->name ?? 'Unknown';

        return $this->availabilityMail((new MailMessage)
            ->subject("Automation \"{$name}\" failed")
            ->line('An automation run failed with the following error:')
            ->line($this->errorMessage), $notifiable);
    }

    public function toOneSignal(object $notifiable): array
    {
        $name = $this->run->automation?->name ?? 'Unknown';

        return [
            'title' => 'Automation failed',
            'body' => "\"{$name}\" — {$this->errorMessage}",
        ];
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'automation_failed')
            ->where('channel', $channel)
            ->first();

        return $pref === null || $pref->enabled;
    }
}
