<?php

namespace App\Notifications;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Concerns\RespectsWorkspaceAvailability;
use App\Notifications\Contracts\WorkspaceWorkNotification;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ConversationAssignedNotification extends Notification implements ShouldQueue, WorkspaceWorkNotification
{
    use Queueable;
    use RespectsWorkspaceAvailability;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ?User $assignedBy,
    ) {
        $this->captureNotificationSource((int) $conversation->workspace_id);
    }

    public function via(object $notifiable): array
    {
        return $this->availabilityChannels($notifiable, ['database', 'broadcast', OneSignalChannel::class]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversation_assigned',
            'silent' => app(NotificationDeliveryPolicy::class)->silent($notifiable, $this),
            'workspace_id' => $this->notificationWorkspaceId(),
            'conversation_id' => $this->conversation->id,
            'assigned_by' => $this->assignedBy?->name,
            'contact_name' => $this->conversation->contact?->name ?? 'Unknown',
            'url' => route('client.inbox.show', $this->conversation),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->availabilityBroadcastData($notifiable));
    }

    public function toOneSignal(object $notifiable): array
    {
        $by = $this->assignedBy?->name ?? 'Someone';
        $contact = $this->conversation->contact?->name ?? 'Unknown';

        return [
            'title' => 'Conversation assigned to you',
            'body' => "{$by} assigned a conversation with {$contact}",
            'url' => route('client.inbox.show', $this->conversation),
        ];
    }
}
