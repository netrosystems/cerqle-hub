<?php

namespace App\Modules\Inbox\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\AiAutomationSetting;
use App\Modules\Shared\Models\ChannelAccount;

class AiAutomationSettings
{
    public const CHANNELS = ['whatsapp', 'instagram', 'messenger'];

    public function group(string $channel): ?string
    {
        return $channel === 'email' ? 'email' : (in_array($channel, self::CHANNELS, true) ? 'channels' : null);
    }

    public function find(int $workspaceId, string $group): ?AiAutomationSetting
    {
        return AiAutomationSetting::where('workspace_id', $workspaceId)->where('group', $group)->first();
    }

    public function available(AiAutomationSetting $setting): bool
    {
        return $setting->mode === 'on' || ($setting->mode === 'scheduled' && app(AiAutomationSchedule::class)->active($setting->weekly_hours, $setting->timezone));
    }

    /** @return array<string, mixed> */
    public function page(int $workspaceId, string $group): array
    {
        $setting = $this->find($workspaceId, $group);
        $bots = AiChatbot::where('workspace_id', $workspaceId)->where('enabled', true)->get(['id', 'name']);
        $legacy = ! $setting && ChannelAccount::where('workspace_id', $workspaceId)
            ->whereIn('channel', $group === 'email' ? ['email'] : self::CHANNELS)->get()
            ->contains(fn ($account) => ! empty($account->meta_json['ai_chatbot_id']));

        return [
            'configured' => (bool) $setting,
            'legacy' => $legacy,
            'mode' => $setting ? $setting->mode : 'off',
            'chatbot_id' => $setting?->chatbot_id,
            'chatbot_name' => $bots->firstWhere('id', $setting?->chatbot_id)?->name,
            'bot_unavailable' => $setting && $setting->mode !== 'off' && ! $bots->firstWhere('id', $setting->chatbot_id),
            'timezone' => $setting ? $setting->timezone : config('app.timezone', 'UTC'),
            'weekly_hours' => $setting ? $setting->weekly_hours : app(AiAutomationSchedule::class)->defaults(),
            'revision' => $setting ? $setting->revision : 0,
            'available' => $setting && $this->available($setting),
            'chatbots' => $bots,
        ];
    }
}
