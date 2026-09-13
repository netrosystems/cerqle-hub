<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Concerns\RespectsWorkspaceAvailability;
use App\Notifications\Contracts\WorkspaceWorkNotification;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceExportReadyNotification extends Notification implements WorkspaceWorkNotification
{
    use Queueable;
    use RespectsWorkspaceAvailability;

    public function __construct(private string $downloadUrl, private ?int $workspaceId = null)
    {
        $this->captureNotificationSource($workspaceId);
    }

    public function allowsUnscopedDelivery(): bool
    {
        return $this->workspaceId === null;
    }

    public function via(object $notifiable): array
    {
        return $this->availabilityChannels($notifiable, ['mail', 'database', OneSignalChannel::class]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->availabilityMail((new MailMessage)
            ->markdown('mail.workspace.export-ready', [
                'name' => $notifiable->name ?? 'there',
                'downloadUrl' => $this->downloadUrl,
            ])
            ->subject('Your data export is ready'), $notifiable);
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => 'Export ready',
            'body' => 'Your workspace data export is ready to download',
            'url' => $this->downloadUrl,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'workspace_export_ready',
            'silent' => app(NotificationDeliveryPolicy::class)->silent($notifiable, $this),
            'workspace_id' => $this->notificationWorkspaceId(),
            'download_url' => $this->downloadUrl,
            'message' => 'Your workspace data export is ready to download.',
        ];
    }
}
