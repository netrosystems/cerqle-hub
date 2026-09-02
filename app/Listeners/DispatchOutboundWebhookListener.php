<?php

namespace App\Listeners;

use App\Events\CampaignCompleted;
use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Models\User;
use App\Services\ClientAccessService;
use App\Services\WebhookDispatchService;

/**
 * Fan-out platform events to registered outbound webhook endpoints.
 */
class DispatchOutboundWebhookListener
{
    public function __construct(
        private readonly WebhookDispatchService $webhookService,
        private readonly ClientAccessService $access,
    ) {}

    public function handleContactCreated(ContactCreated $event): void
    {
        $contact = $event->contact;
        $user = User::inWorkspace($contact->workspace_id)->first();
        if (! $user || ! $this->access->allowsWorkspaceWrite($contact->workspace_id)) {
            return;
        }

        $this->webhookService->dispatch($user, 'contact.created', [
            'event' => 'contact.created',
            'contact' => [
                'id' => $contact->id,
                'phone_e164' => $contact->phone_e164,
                'email' => $contact->email,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
            ],
        ]);
    }

    public function handleMessageReceived(MessageReceived $event): void
    {
        $message = $event->message;
        $conversation = $message->conversation;
        if (! $conversation) {
            return;
        }

        $user = User::inWorkspace($conversation->workspace_id)->first();
        if (! $user || ! $this->access->allowsWorkspaceWrite($conversation->workspace_id)) {
            return;
        }

        $this->webhookService->dispatch($user, 'message.received', [
            'event' => 'message.received',
            'message' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'direction' => $message->direction,
                'channel' => $message->channel,
                'body' => $message->body,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ],
        ]);
    }

    public function handleCampaignCompleted(CampaignCompleted $event): void
    {
        $campaign = $event->campaign;
        $user = User::inWorkspace($campaign->workspace_id)->first();
        if (! $user || ! $this->access->allowsWorkspaceWrite($campaign->workspace_id)) {
            return;
        }

        $this->webhookService->dispatch($user, 'campaign.completed', [
            'event' => 'campaign.completed',
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'channel' => $campaign->channel,
                'status' => $campaign->status,
                'totals' => $campaign->totals_json ?? [],
            ],
        ]);
    }
}
