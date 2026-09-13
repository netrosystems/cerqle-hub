<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Modules\Broadcasting\Models\Campaign;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Concerns\RespectsWorkspaceAvailability;
use App\Notifications\Contracts\WorkspaceWorkNotification;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignCompletedNotification extends Notification implements ShouldQueue, WorkspaceWorkNotification
{
    use Queueable;
    use RespectsWorkspaceAvailability;

    public function __construct(public readonly Campaign $campaign)
    {
        $this->captureNotificationSource((int) $campaign->workspace_id);
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
            'type' => 'campaign_completed',
            'silent' => app(NotificationDeliveryPolicy::class)->silent($notifiable, $this),
            'workspace_id' => $this->notificationWorkspaceId(),
            'campaign_id' => $this->campaign->id,
            'name' => $this->campaign->name,
            'sent' => $this->campaign->sent_count ?? 0,
            'failed' => $this->campaign->failed_count ?? 0,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->availabilityBroadcastData($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->availabilityMail((new MailMessage)
            ->subject("Campaign \"{$this->campaign->name}\" completed")
            ->line("Your campaign \"{$this->campaign->name}\" has finished sending.")
            ->line("Sent: {$this->campaign->sent_count}, Failed: {$this->campaign->failed_count}"), $notifiable);
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => 'Campaign completed',
            'body' => "\"{$this->campaign->name}\" — Sent: {$this->campaign->sent_count}, Failed: {$this->campaign->failed_count}",
        ];
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'campaign_completed')
            ->where('channel', $channel)
            ->first();

        return $pref === null || $pref->enabled;
    }
}
