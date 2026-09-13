<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\NotificationAvailability;
use App\Services\NotificationDeliveryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

trait RespectsWorkspaceAvailability
{
    private ?int $sourceWorkspaceId = null;

    private ?CarbonImmutable $notificationOccurredAt = null;

    /** @var array<int, bool> */
    private array $recipientAvailability = [];

    protected function captureNotificationSource(?int $workspaceId, ?CarbonImmutable $at = null): void
    {
        $this->sourceWorkspaceId = $workspaceId;
        $this->notificationOccurredAt = $at ?? CarbonImmutable::now();
    }

    public function notificationWorkspaceId(): ?int
    {
        return $this->sourceWorkspaceId;
    }

    public function allowsUnscopedDelivery(): bool
    {
        return false;
    }

    public function notificationPreferenceEvent(): string
    {
        return match (class_basename($this)) {
            'NewMessageNotification' => 'new_message',
            'ConversationAssignedNotification' => 'conversation_assigned',
            'MentionedInNoteNotification' => 'mention',
            'ConversationHandoverNotification' => 'handover',
            'PendingCustomerReplyNotification' => 'pending_customer_reply',
            'CampaignCompletedNotification' => 'campaign_completed',
            'AutomationFailedNotification' => 'automation_failed',
            'WorkspaceExportReadyNotification' => 'workspace_export_ready',
        };
    }

    public function initialAvailability(User $user): bool
    {
        // via() runs on the original notification before recipient-specific
        // queue clones are made. Never share one recipient's decision with another.
        return $this->recipientAvailability[$user->id] ??= $this->sourceWorkspaceId !== null
            && $this->notificationOccurredAt !== null
            && app(NotificationAvailability::class)->available($user, $this->sourceWorkspaceId, $this->notificationOccurredAt);
    }

    /** @param list<string> $channels
     * @return list<string>
     */
    protected function availabilityChannels(object $notifiable, array $channels): array
    {
        if ($notifiable instanceof User) {
            $this->initialAvailability($notifiable);
        }

        return array_values(array_filter($channels, fn (string $channel) => $this->shouldSend($notifiable, $channel)));
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(NotificationDeliveryPolicy::class)->allows($notifiable, $this, $channel);
    }

    /** @return array<string, mixed> */
    protected function availabilityBroadcastData(object $notifiable): array
    {
        return array_merge($this->toArray($notifiable), [
            'silent' => app(NotificationDeliveryPolicy::class)->silent($notifiable, $this),
        ]);
    }

    protected function availabilityMail(MailMessage $message, object $notifiable): MailMessage
    {
        // Server-only context for the final SMTP MessageSending guard. No
        // credentials or availability schedule are rendered into the email.
        $message->viewData['__cerqle_work_notification'] = $this;
        $message->viewData['__cerqle_work_recipient'] = $notifiable;

        return $message;
    }
}
