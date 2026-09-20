<?php

namespace App\Modules\Inbox\Services;

/**
 * The vocabulary for "why did this inbound message get no bot reply?".
 *
 * Routing has seven distinct skip decisions that all used to land as an
 * indistinguishable row with a null reason. These codes are stable and
 * machine-readable; the human sentence stays in the existing `reason` column.
 */
class AiRoutingReason
{
    // Routing decisions
    public const WORKSPACE_WRITE_BLOCKED = 'workspace_write_blocked';

    public const CHANNEL_ACCOUNT_INVALID = 'channel_account_invalid';

    public const HUMAN_OWNED = 'human_owned';

    public const EMAIL_SUPPRESSED = 'email_suppressed';

    public const HISTORY_IMPORT = 'history_import';

    public const HANDOVER_REQUESTED = 'handover_requested';

    public const WORKFLOW_OWNED = 'workflow_owned';

    public const MESSAGE_SUPPRESSED = 'message_suppressed';

    public const RULE_MATCHED = 'rule_matched';

    public const NO_CHATBOT_ASSIGNED = 'no_chatbot_assigned';

    public const GROUP_UNAVAILABLE = 'group_unavailable';

    public const MESSAGING_QUOTA_FULL = 'messaging_quota_full';

    public const QUEUED = 'queued';

    /** Widget availability failures, mirrored from WidgetAiAvailability::reason(). */
    public const WIDGET_DISABLED = 'widget_disabled';

    public const AI_SWITCH_OFF = 'ai_switch_off';

    public const CHATBOT_MISSING = 'chatbot_missing';

    public const MODE_OFF = 'mode_off';

    public const OUTSIDE_HOURS = 'outside_hours';

    public const SCHEDULE_ERROR = 'schedule_error';

    /**
     * A short operator-facing sentence for a code.
     *
     * Deliberately plain: this is read in the inbox by someone wondering why a
     * customer was ignored, not by an engineer reading logs.
     */
    public static function describe(?string $code): ?string
    {
        return match ($code) {
            self::WORKSPACE_WRITE_BLOCKED => 'No automatic reply — this account cannot send messages right now.',
            self::CHANNEL_ACCOUNT_INVALID => 'No automatic reply — the channel connection is missing or misconfigured.',
            self::HUMAN_OWNED => 'No automatic reply — a team member owns this conversation.',
            self::EMAIL_SUPPRESSED => 'No automatic reply — this email was suppressed as automated or looping.',
            self::HISTORY_IMPORT => 'No automatic reply — this message came from an imported history.',
            self::HANDOVER_REQUESTED => 'No automatic reply — the customer asked for a person.',
            self::WORKFLOW_OWNED => 'No automatic reply — an automation handled this message.',
            self::MESSAGE_SUPPRESSED => 'No automatic reply — replies are paused for this chat.',
            self::RULE_MATCHED => 'Answered by a keyword auto-reply rule.',
            self::NO_CHATBOT_ASSIGNED => 'No automatic reply — no Smart Bot is assigned to this channel.',
            self::GROUP_UNAVAILABLE => 'No automatic reply — AI is off or outside its hours for this channel group.',
            self::MESSAGING_QUOTA_FULL => 'No automatic reply — the monthly message limit is reached.',
            self::WIDGET_DISABLED => 'No automatic reply — this widget is disabled.',
            self::AI_SWITCH_OFF => 'No automatic reply — "Let a Smart Bot answer first" is off for this widget.',
            self::CHATBOT_MISSING => 'No automatic reply — the selected Smart Bot is missing or disabled.',
            self::MODE_OFF => 'No automatic reply — the widget\'s AI mode is Off.',
            self::OUTSIDE_HOURS => 'No automatic reply — AI is outside its scheduled hours.',
            self::SCHEDULE_ERROR => 'No automatic reply — the AI schedule or timezone is invalid and needs fixing.',
            default => null,
        };
    }
}
