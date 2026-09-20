<?php

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use Carbon\Carbon;

/**
 * Whether a customer asking for a person can expect one soon.
 *
 * This reads configured business hours and whether the workspace has any active
 * members. It is deliberately NOT a presence system: the 2026-09-16 decision
 * records that notification availability must not be treated as presence, so
 * nothing here claims a particular human is at their desk. It answers the much
 * weaker question the customer actually needs — "are you open?".
 */
class AgentAvailability
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function forConversation(Conversation $conversation): bool
    {
        $widget = ChatWidget::where('workspace_id', $conversation->workspace_id)
            ->where('channel_account_id', $conversation->channel_account_id)
            ->first();

        return $this->available($conversation->workspace_id, $widget);
    }

    public function available(int $workspaceId, ?ChatWidget $widget = null): bool
    {
        if (! $this->hasActiveMembers($workspaceId)) {
            return false;
        }

        // No configured hours means the client has not claimed to be closed.
        return $widget === null || $this->withinWorkingHours($widget);
    }

    /** When the team next opens, for setting an expectation rather than inventing one. */
    public function nextOpening(?ChatWidget $widget): ?Carbon
    {
        $hours = $widget?->working_hours_json;
        if (empty($hours) || empty($hours['enabled'])) {
            return null;
        }

        $now = $this->now($hours);
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->copy()->addDays($offset);
            $schedule = $hours['schedule'][self::DAYS[(int) $day->format('w')]] ?? null;
            if (empty($schedule) || empty($schedule['enabled'])) {
                continue;
            }
            [$hour, $minute] = array_pad(explode(':', (string) ($schedule['open'] ?? '00:00')), 2, '0');
            $opening = $day->copy()->setTime((int) $hour, (int) $minute);
            if ($opening->gt($now)) {
                return $opening;
            }
        }

        return null;
    }

    private function hasActiveMembers(int $workspaceId): bool
    {
        return User::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', User::STATUS_ACTIVE)
            ->exists();
    }

    /** The same rule the widget already shows visitors as its online state. */
    private function withinWorkingHours(ChatWidget $widget): bool
    {
        $hours = $widget->working_hours_json;
        if (empty($hours) || empty($hours['enabled'])) {
            return true;
        }

        $now = $this->now($hours);
        $schedule = $hours['schedule'][self::DAYS[(int) $now->format('w')]] ?? null;
        if (empty($schedule) || empty($schedule['enabled'])) {
            return false;
        }

        $current = (int) $now->format('H') * 60 + (int) $now->format('i');
        [$openHour, $openMinute] = array_pad(explode(':', (string) ($schedule['open'] ?? '00:00')), 2, '0');
        [$closeHour, $closeMinute] = array_pad(explode(':', (string) ($schedule['close'] ?? '23:59')), 2, '0');

        return $current >= ((int) $openHour * 60 + (int) $openMinute)
            && $current < ((int) $closeHour * 60 + (int) $closeMinute);
    }

    /** @param array<string, mixed> $hours */
    private function now(array $hours): Carbon
    {
        try {
            return now()->setTimezone($hours['timezone'] ?? 'UTC');
        } catch (\Throwable) {
            return now();
        }
    }
}
