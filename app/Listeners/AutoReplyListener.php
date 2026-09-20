<?php

namespace App\Listeners;

use App\Events\MessageReceived;
use App\Events\MessageSent;
use App\Modules\AI\Services\Smart\CannedPhrases;
use App\Modules\AI\Services\Smart\ConversationLanguage;
use App\Modules\Inbox\Jobs\GenerateGroupedAiReply;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\InboundReplyOwnership;
use App\Modules\Inbox\Services\AgentAvailability;
use App\Modules\Inbox\Services\AiAutomationSettings;
use App\Modules\Inbox\Services\AiReplyEligibility;
use App\Modules\Inbox\Services\AiRoutingReason;
use App\Modules\Inbox\Services\ConversationHandoverService;
use App\Modules\Inbox\Services\HandoverIntent;
use App\Modules\Inbox\Services\WidgetAiAvailability;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Models\WhatsappAutoReply;
use App\Services\ClientAccessService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Phrases that trigger AI-to-human handover.
 * Case-insensitive substring matching.
 */
const HANDOVER_PHRASES = [
    'talk to human', 'talk to agent', 'speak to agent', 'speak to human',
    'human please', 'real person', 'live agent', 'live support',
    'need a human', 'connect me to', 'transfer me',
];

class AutoReplyListener
{
    public function __construct(
        private readonly ChannelManager $channelManager,
        private readonly ConversationHandoverService $handoverService,
        private readonly ClientAccessService $access,
    ) {}

    public function handle(MessageReceived $event): void
    {
        $msgId = $event->message->id ?? null;

        // Deduplication: ensure we never auto-reply twice for the same inbound message.
        // Durable unique ownership prevents races and late redelivery.
        $conversation = $event->message->conversation;
        if (! $msgId || ! $conversation || $event->message->direction !== 'in') {
            return;
        }
        if (! InboundReplyOwnership::insertOrIgnore([
            'message_id' => $msgId, 'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id, 'channel_account_id' => $conversation->channel_account_id,
            'owner' => 'routing', 'status' => 'claimed', 'created_at' => now(), 'updated_at' => now(),
        ])) {
            return;
        }

        try {
            $this->process($event);
        } catch (\Throwable $e) {
            InboundReplyOwnership::where('workspace_id', $conversation->workspace_id)->where('message_id', $msgId)->update(['status' => 'failed', 'reason' => 'Inbound routing failed; review required.']);
            Log::error('AutoReplyListener unhandled exception', [
                'message_id' => $msgId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    private function process(MessageReceived $event): void
    {
        $message = $event->message;

        if ($message->direction !== 'in') {
            return;
        }

        $conversation = $message->conversation;
        if (! $conversation || ! $this->access->allowsWorkspaceWrite($conversation->workspace_id)) {
            if ($conversation) {
                $this->ownership($message, 'human', 'skipped', null, AiRoutingReason::WORKSPACE_WRITE_BLOCKED);
            }

            return;
        }
        $channelAccount = $conversation->channelAccount;

        if (! $channelAccount || (int) $channelAccount->workspace_id !== (int) $conversation->workspace_id) {
            $this->ownership($message, 'human', 'skipped', null, AiRoutingReason::CHANNEL_ACCOUNT_INVALID);

            return;
        }

        $eligibility = app(AiReplyEligibility::class);
        $humanOwnedReason = match (true) {
            $eligibility->humanOwned($conversation, $message->channel) => AiRoutingReason::HUMAN_OWNED,
            $message->channel === 'email' && $eligibility->suppressed($message) => AiRoutingReason::EMAIL_SUPPRESSED,
            $message->origin === 'whatsapp_history' => AiRoutingReason::HISTORY_IMPORT,
            default => null,
        };
        if ($humanOwnedReason !== null) {
            $this->ownership($message, 'human', 'skipped', null, $humanOwnedReason);

            return;
        }

        // Embedding is deliberately not allowed here: this listener runs inside
        // the customer's own send request, so a provider call would be latency
        // they wait through. The queued reply job does the fuller check.
        if (app(HandoverIntent::class)->wants($message, (int) $conversation->workspace_id, allowEmbedding: false)) {
            $this->triggerHandover($conversation, 'user_request', $message);
            $this->ownership($message, 'human', 'completed', null, AiRoutingReason::HANDOVER_REQUESTED);

            return;
        }
        if (app(AutomationTriggerListener::class)->routeMessage($event)) {
            $this->ownership($message, 'workflow', 'completed', null, AiRoutingReason::WORKFLOW_OWNED);

            return;
        }
        if ($eligibility->suppressed($message)) {
            $this->ownership($message, 'ai', 'skipped', null, AiRoutingReason::MESSAGE_SUPPRESSED);

            return;
        }

        // ── 1. Keyword / trigger auto-reply rules (always run, no chatbot required) ──
        $autoReply = $this->findMatchingAutoReply(
            $conversation->workspace_id,
            $channelAccount->id,
            $message,
            $conversation,
            $message->channel,
        );

        if ($autoReply) {
            $this->ownership($message, 'rule', 'sending', null, AiRoutingReason::RULE_MATCHED);
            $this->dispatchAutoReply($autoReply, $message, $conversation);

            return;
        }

        // ── 3. AI chatbot (only if one is linked to this channel account) ─────
        if ($message->channel === 'webchat') {
            $widget = ChatWidget::where('workspace_id', $conversation->workspace_id)->where('channel_account_id', $channelAccount->id)->first();
            $availability = app(WidgetAiAvailability::class);
            if (! $widget) {
                $this->ownership($message, 'ai', 'skipped', null, AiRoutingReason::CHATBOT_MISSING);

                return;
            }
            // Checked for now and for when the message arrived, so a boundary
            // crossed mid-queue cannot produce a late reply.
            $unavailable = $availability->reason($widget)
                ?? $availability->reason($widget, CarbonImmutable::parse($message->created_at));
            if ($unavailable !== null) {
                $this->ownership($message, 'ai', 'skipped', null, $unavailable);

                return;
            }
            $this->ownership($message, 'ai', 'queued', $widget->ai_revision, AiRoutingReason::QUEUED);
            GenerateGroupedAiReply::dispatch($message->id, $conversation->workspace_id, $channelAccount->id, (int) $widget->ai_chatbot_id, null, $widget->id, $widget->ai_revision)->onQueue('ai');

            return;
        }
        $settings = app(AiAutomationSettings::class);
        $group = $settings->group($message->channel);
        $setting = $group ? $settings->find($conversation->workspace_id, $group) : null;
        if ($setting && (! $settings->available($setting) || ! $setting->activated_at || $message->sent_at < $setting->activated_at)) {
            $this->ownership($message, 'ai', 'skipped', null, AiRoutingReason::GROUP_UNAVAILABLE);

            return;
        }
        $chatbotId = $setting ? $setting->chatbot_id : ($channelAccount->meta_json['ai_chatbot_id'] ?? null);
        if (! $chatbotId) {
            $this->ownership($message, 'ai', 'skipped', null, AiRoutingReason::NO_CHATBOT_ASSIGNED);

            return;
        }

        $this->ownership($message, 'ai', 'queued', $setting?->revision, AiRoutingReason::QUEUED);
        GenerateGroupedAiReply::dispatch($message->id, $conversation->workspace_id, $channelAccount->id, (int) $chatbotId, $setting?->revision)->onQueue('ai');
    }

    private function ownership(Message $message, string $owner, string $status, ?int $revision = null, ?string $reasonCode = null): void
    {
        $attributes = ['owner' => $owner, 'status' => $status, 'settings_revision' => $revision];

        // The code is the stable machine-readable value; the sentence beside it
        // is what an operator reads in the inbox.
        if ($reasonCode !== null) {
            $attributes['reason_code'] = $reasonCode;
            $attributes['reason'] = AiRoutingReason::describe($reasonCode);
        }

        InboundReplyOwnership::where('workspace_id', $message->conversation->workspace_id)
            ->where('message_id', $message->id)
            ->update($attributes);
    }

    private function findMatchingAutoReply(
        int $workspaceId,
        int $channelAccountId,
        Message $message,
        Conversation $conversation,
        string $channel = 'whatsapp',
    ): ?WhatsappAutoReply {
        $rules = WhatsappAutoReply::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->where(function ($q) use ($channelAccountId, $channel) {
                // Global rules (no channel account) only apply to WhatsApp
                if ($channel === 'whatsapp') {
                    $q->whereNull('channel_account_id')
                        ->orWhere('channel_account_id', $channelAccountId);
                } else {
                    $q->where('channel_account_id', $channelAccountId);
                }
            })
            ->orderBy('priority')
            ->get();

        $body = $message->body ?? '';
        $isFirstMessage = $conversation->messages()->count() === 1;

        foreach ($rules as $rule) {
            $matched = match ($rule->trigger_type) {
                'keyword' => $rule->matchesMessage($body),
                'welcome' => $isFirstMessage,
                'away' => $this->isOutsideSchedule($rule->schedule_json),
                'out_of_hours' => $this->isOutsideSchedule($rule->schedule_json),
                default => false,
            };
            if ($matched) {
                return $rule;
            }
        }

        return null;
    }

    private function dispatchAutoReply(WhatsappAutoReply $rule, Message $inbound, Conversation $conversation): void
    {
        $payload = $rule->payload_json ?? [];

        [$type, $body, $msgPayload] = match ($rule->response_kind) {
            'template' => [
                'template',
                $payload['template_name'] ?? '',
                ['template' => [
                    'name' => $payload['template_name'] ?? '',
                    'language' => $payload['language'] ?? 'en',
                    'components' => $payload['components'] ?? [],
                ]],
            ],
            'media' => [
                $payload['media_type'] ?? 'image',
                $payload['caption'] ?? '(media)',
                $payload,
            ],
            default => ['text', $payload['text'] ?? '', null],
        };

        if (empty($body) && $type === 'text') {
            return;
        }

        try {
            $botMessage = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $inbound->channel,
                'type' => $type,
                'body' => $body,
                'payload' => array_merge($msgPayload ?? [], ['reply_to_message_id' => $inbound->id]),
                'status' => 'queued',
                'sent_by' => 'bot',
                'sent_at' => now(),
            ]);

            try {
                $driver = $this->channelManager->driver($inbound->channel);
                $providerId = $driver->send($botMessage);
                $botMessage->update(['status' => 'sent', 'provider_message_id' => $providerId]);
                InboundReplyOwnership::where('workspace_id', $conversation->workspace_id)->where('message_id', $inbound->id)->update(['status' => 'completed', 'outbound_message_id' => $botMessage->id]);
            } catch (\Throwable $sendErr) {
                $botMessage->update(['status' => 'failed', 'error_json' => ['message' => $sendErr->getMessage()]]);
                InboundReplyOwnership::where('workspace_id', $conversation->workspace_id)->where('message_id', $inbound->id)->update(['status' => 'delivery_review', 'outbound_message_id' => $botMessage->id]);
                Log::warning('AutoReplyListener auto-reply send failed', [
                    'rule_id' => $rule->id,
                    'error' => $sendErr->getMessage(),
                ]);
            }

            $conversation->update(['last_message_at' => now()]);
            $botMessage->load('conversation');
            MessageSent::dispatch($botMessage);
        } catch (\Throwable $e) {
            Log::error('AutoReplyListener dispatchAutoReply failed', [
                'rule_id' => $rule->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Returns true when the current time falls OUTSIDE the defined business hours window.
     * schedule_json shape: { days: [1-5], start: "HH:MM", end: "HH:MM", timezone: "TZ" }
     * Days follow ISO-8601: 1=Monday … 7=Sunday.
     */
    private function isOutsideSchedule(?array $schedule): bool
    {
        if (empty($schedule)) {
            return false;
        }

        $timezone = $schedule['timezone'] ?? 'UTC';
        try {
            $now = Carbon::now(new \DateTimeZone($timezone));
        } catch (\Exception) {
            $now = Carbon::now();
        }

        $allowedDays = array_map('intval', $schedule['days'] ?? [1, 2, 3, 4, 5]);
        $dayOfWeek = $now->isoWeekday(); // 1=Mon … 7=Sun

        if (! in_array($dayOfWeek, $allowedDays, true)) {
            return true; // today is not a business day
        }

        $startParts = explode(':', $schedule['start'] ?? '09:00');
        $endParts = explode(':', $schedule['end'] ?? '18:00');

        $startMinutes = ((int) ($startParts[0] ?? 9)) * 60 + (int) ($startParts[1] ?? 0);
        $endMinutes = ((int) ($endParts[0] ?? 18)) * 60 + (int) ($endParts[1] ?? 0);
        $nowMinutes = $now->hour * 60 + $now->minute;

        return $nowMinutes < $startMinutes || $nowMinutes >= $endMinutes;
    }

    private function triggerHandover(Conversation $conversation, string $reason, ?Message $inbound = null): void
    {
        $created = $this->handoverService->request($conversation, $reason);

        if ($created && $inbound) {
            $this->acknowledgeWhenNobodyIsAvailable($conversation, $inbound);
        }
    }

    /**
     * Tell the customer what happens next when the team is closed.
     *
     * A handover outside working hours used to be silent: the conversation was
     * queued and notifications fired, but the customer saw nothing at all and
     * had no idea whether anyone had received their message.
     */
    private function acknowledgeWhenNobodyIsAvailable(Conversation $conversation, Message $inbound): void
    {
        if (! config('ai.smart_bot.no_agent_holding_reply')) {
            return;
        }

        $widget = $conversation->channel_account_id
            ? ChatWidget::where('workspace_id', $conversation->workspace_id)
                ->where('channel_account_id', $conversation->channel_account_id)
                ->first()
            : null;

        if (app(AgentAvailability::class)->available((int) $conversation->workspace_id, $widget)) {
            return;
        }

        // The client's own offline wording wins: they wrote it, in their own
        // voice, and it needs no translation.
        $body = trim((string) ($widget?->offline_message ?? ''));
        if ($body === '') {
            $body = app(CannedPhrases::class)->get(
                'no_agent_available',
                app(ConversationLanguage::class)->resolve($conversation->id),
                (int) $conversation->workspace_id,
                $inbound->body,
            );
        }

        $opening = app(AgentAvailability::class)->nextOpening($widget);
        if ($opening) {
            $body .= ' '.__('We are back at :time.', ['time' => $opening->isoFormat('ddd HH:mm')]);
        }

        $this->botReply($inbound, $conversation, $body);
    }

    /** Sends a zero-credit bot message that is not a generated answer. */
    private function botReply(Message $inbound, Conversation $conversation, string $body): void
    {
        if (trim($body) === '') {
            return;
        }

        try {
            $outbound = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $inbound->channel,
                'type' => 'text',
                'body' => $body,
                'payload' => [
                    'reply_to_message_id' => $inbound->id,
                    'ai_automation' => true,
                    'ai_answer' => ['answer_origin' => 'conversation', 'response_mode' => 'answer', 'quick_replies' => []],
                ],
                'status' => 'queued',
                'sent_by' => 'bot',
                'sent_at' => now(),
            ]);

            $providerId = $this->channelManager->driver($inbound->channel)->send($outbound);
            $outbound->update(['status' => 'sent', 'provider_message_id' => $providerId]);
            $conversation->update(['last_message_at' => now()]);
            MessageSent::dispatch($outbound->load('conversation'));
        } catch (\Throwable $error) {
            // A missing acknowledgement must never break routing.
            Log::warning('AutoReplyListener holding reply failed', [
                'conversation_id' => $conversation->id,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
