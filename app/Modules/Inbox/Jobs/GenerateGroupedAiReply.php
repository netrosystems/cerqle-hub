<?php

namespace App\Modules\Inbox\Jobs;

use App\Events\MessageSent;
use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Exceptions\AiRateLimitException;
use App\Modules\AI\Exceptions\AiRequestInProgressException;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\InboundReplyOwnership;
use App\Modules\Inbox\Services\AiAutomationSettings;
use App\Modules\Inbox\Services\AiReplyEligibility;
use App\Modules\Inbox\Services\WidgetAiAvailability;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Services\ClientAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class GenerateGroupedAiReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $timeout = 120;

    public ?int $widgetId = null;

    public ?int $widgetRevision = null;

    public function __construct(public int $messageId, public int $workspaceId, public int $accountId, public int $chatbotId, public ?int $revision, ?int $widgetId = null, ?int $widgetRevision = null)
    {
        $this->widgetId = $widgetId;
        $this->widgetRevision = $widgetRevision;
        $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', 180);
        if ($retryAfter <= 10) {
            throw new \RuntimeException('AI queue retry_after must exceed ten seconds.');
        }
        $this->timeout = min(120, $retryAfter - 10);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $conversationId = InboundReplyOwnership::where('workspace_id', $this->workspaceId)->where('message_id', $this->messageId)->value('conversation_id');

        return [(new WithoutOverlapping('ai-reply:'.$this->workspaceId.':'.$conversationId))->shared()->releaseAfter(5)->expireAfter(180)];
    }

    public function handle(ChatbotRunner $runner, ChannelManager $channels, ClientAccessService $access): void
    {
        $receipt = InboundReplyOwnership::where('workspace_id', $this->workspaceId)->where('message_id', $this->messageId)->where('owner', 'ai')->first();
        if ($receipt && in_array($receipt->status, ['generating', 'sending'], true)) {
            $this->failed(null);

            return;
        }
        if (! $receipt || $receipt->status !== 'queued') {
            return;
        }
        // A durable attempt, not a cache TTL, protects against late duplicate jobs/crashes.
        if (! InboundReplyOwnership::whereKey($receipt->id)->where('status', 'queued')->update(['status' => 'generating'])) {
            return;
        }
        $inbound = Message::whereKey($this->messageId)->where('direction', 'in')
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $this->workspaceId)->where('channel_account_id', $this->accountId))
            ->with('conversation.channelAccount', 'conversation.contact')->first();
        if (! $inbound || ! $this->eligible($inbound, $access)) {
            $receipt->update(['status' => 'skipped', 'reason' => 'Eligibility changed before generation.']);

            return;
        }
        $bot = AiChatbot::where('workspace_id', $this->workspaceId)->where('enabled', true)->find($this->chatbotId);
        if (! $bot) {
            $receipt->update(['status' => 'failed', 'reason' => 'Selected chatbot unavailable.']);
            $this->failureActivity($inbound, 'Selected chatbot unavailable.');

            return;
        }
        $outbound = null;
        try {
            try {
                $reply = $runner->run($bot, $inbound, true);
            } catch (AiCreditsExhaustedException|AiRateLimitException|AiRequestInProgressException $error) {
                throw $error;
            } catch (\Throwable $error) {
                if (blank($bot->fallback_reply)) {
                    throw $error;
                }
                $reply = $bot->fallback_reply;
            }
            if (! $reply) {
                throw new \RuntimeException('Chatbot returned no reply.');
            }
            $inbound->unsetRelation('conversation');
            $inbound->load('conversation.channelAccount', 'conversation.contact');
            if (! $this->eligible($inbound, $access) || ! AiChatbot::where('workspace_id', $this->workspaceId)->where('enabled', true)->whereKey($this->chatbotId)->exists()) {
                $receipt->update(['status' => 'skipped', 'reason' => 'Eligibility changed while generating.']);

                return;
            }
            $outbound = Message::create([
                'conversation_id' => $inbound->conversation_id, 'direction' => 'out', 'channel' => $inbound->channel,
                'type' => 'text', 'body' => $reply, 'payload' => ['reply_to_message_id' => $inbound->id, 'ai_automation' => true],
                'status' => 'queued', 'sent_by' => 'bot', 'sent_at' => now(),
            ]);
            $receipt->update(['outbound_message_id' => $outbound->id, 'status' => 'sending']);
            $providerId = $channels->driver($inbound->channel)->send($outbound);
            $outbound->update(['status' => 'sent', 'provider_message_id' => $providerId]);
            $receipt->update(['status' => 'completed']);
            $inbound->conversation->update(['last_message_at' => now()]);
            MessageSent::dispatch($outbound->load('conversation'));
        } catch (\Throwable $error) {
            // Unknown send outcomes are never automatically retried.
            $reason = $outbound ? 'Delivery outcome requires review.' : 'AI generation failed; check chatbot, credits and provider access.';
            $receipt->update(['status' => $outbound ? 'delivery_review' : 'failed', 'reason' => $reason]);
            if (! $outbound) {
                $outbound = Message::create([
                    'conversation_id' => $inbound->conversation_id, 'direction' => 'out', 'channel' => $inbound->channel,
                    'type' => 'text', 'body' => '', 'payload' => ['reply_to_message_id' => $inbound->id, 'ai_automation' => true],
                    'status' => 'failed', 'sent_by' => 'bot', 'sent_at' => now(),
                ]);
            }
            $outbound->update(['status' => 'failed', 'error_json' => ['message' => $reason]]);
            MessageSent::dispatch($outbound->load('conversation'));
        }
    }

    private function failureActivity(Message $inbound, string $reason): void
    {
        $message = Message::create([
            'conversation_id' => $inbound->conversation_id, 'direction' => 'out', 'channel' => $inbound->channel,
            'type' => 'text', 'body' => '', 'payload' => ['reply_to_message_id' => $inbound->id, 'ai_automation' => true],
            'status' => 'failed', 'sent_by' => 'bot', 'sent_at' => now(), 'error_json' => ['message' => $reason],
        ]);
        MessageSent::dispatch($message->load('conversation'));
    }

    public function failed(?\Throwable $error): void
    {
        $receipt = InboundReplyOwnership::where('workspace_id', $this->workspaceId)->where('message_id', $this->messageId)->whereIn('status', ['queued', 'generating', 'sending'])->first();
        if (! $receipt) {
            return;
        }
        $receipt->update(['status' => 'delivery_review', 'reason' => 'Worker interrupted; delivery review required. No automatic retry.']);
        $inbound = Message::whereKey($this->messageId)->whereHas('conversation', fn ($q) => $q->where('workspace_id', $this->workspaceId)->where('channel_account_id', $this->accountId))->first();
        if ($inbound) {
            $this->failureActivity($inbound, 'Worker interrupted; review AI delivery before replying manually.');
        }
    }

    private function eligible(Message $message, ClientAccessService $access): bool
    {
        $conversation = $message->conversation;
        $account = $conversation->channelAccount;
        $eligibility = app(AiReplyEligibility::class);
        if (! $account || (int) $account->workspace_id !== $this->workspaceId || $account->status !== 'active'
            || ! $access->allowsWorkspaceWrite($this->workspaceId)
            || $eligibility->humanOwned($conversation, $message->channel) || $eligibility->suppressed($message)
            || ! $conversation->isWhatsappWindowOpen()) {
            return false;
        }
        if ($message->sent_at < now()->subMinutes(10) || $message->sent_at > now()) {
            return false;
        }
        $settings = app(AiAutomationSettings::class);
        if ($message->channel === 'webchat') {
            $widget = ChatWidget::where('workspace_id', $this->workspaceId)->where('channel_account_id', $this->accountId)->whereKey($this->widgetId)->first();
            $availability = app(WidgetAiAvailability::class);

            return $widget && $widget->ai_revision === $this->widgetRevision && (int) $widget->ai_chatbot_id === $this->chatbotId
                && $availability->available($widget) && $availability->available($widget, CarbonImmutable::parse($message->created_at));
        }
        $group = $settings->group($message->channel);
        $setting = $group ? $settings->find($this->workspaceId, $group) : null;

        return $this->revision === null
            ? ! $setting && (int) ($account->meta_json['ai_chatbot_id'] ?? 0) === $this->chatbotId
            : $setting && $setting->revision === $this->revision && (int) $setting->chatbot_id === $this->chatbotId
                && $settings->available($setting) && $setting->activated_at && $message->sent_at >= $setting->activated_at;
    }
}
