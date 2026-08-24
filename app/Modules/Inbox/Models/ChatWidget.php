<?php

namespace App\Modules\Inbox\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\ChannelAccount;
use App\Services\StorageManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A website live-chat widget. Owns one `webchat` channel_account and stores the
 * theming + behaviour served to the embeddable script and used by the inbox.
 */
class ChatWidget extends Model
{
    protected $table = 'chat_widgets';

    protected $fillable = [
        'workspace_id', 'channel_account_id', 'widget_key', 'name',
        'title', 'subtitle', 'welcome_message', 'agent_name', 'avatar_url',
        'avatar_path', 'avatar_disk',
        'primary_color', 'position', 'launcher_text', 'footer_company_name',
        'launcher_logo_path', 'launcher_logo_disk',
        'ai_enabled', 'ai_chatbot_id', 'require_prechat', 'prechat_fields',
        'offline_message', 'allowed_domains', 'working_hours_json', 'enabled',
        'identity_verification', 'identity_secret',
    ];

    protected $hidden = ['identity_secret'];

    protected $appends = ['launcher_logo_url'];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'require_prechat' => 'boolean',
            'enabled' => 'boolean',
            'identity_verification' => 'boolean',
            'prechat_fields' => 'array',
            'allowed_domains' => 'array',
            'working_hours_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->widget_key)) {
                $model->widget_key = Str::random(32);
            }
            if (empty($model->identity_secret)) {
                $model->identity_secret = Str::random(48);
            }
        });
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function getLauncherLogoUrlAttribute(): ?string
    {
        if (! $this->launcher_logo_path || ! $this->canUseCustomLauncherLogo()) {
            return null;
        }

        $disk = $this->launcher_logo_disk ?: app(StorageManager::class)->diskName();

        return Storage::disk($disk)->url($this->launcher_logo_path);
    }

    /** Prefer the managed upload while retaining legacy URL-based avatars. */
    public function getAvatarUrlAttribute(?string $legacyUrl): ?string
    {
        if (! $this->avatar_path) {
            return $legacyUrl;
        }

        $storageManager = app(StorageManager::class);
        $disk = $this->avatar_disk ?: $storageManager->diskName();
        $storageManager->ensureDiskReady($disk);

        return Storage::disk($disk)->url($this->avatar_path);
    }

    private function canUseCustomLauncherLogo(): bool
    {
        $workspace = $this->workspace
            ?: $this->workspace()->with(['client.activeSubscription.plan', 'owner.activeSubscription.plan'])->first();

        $plan = $workspace?->client?->effectivePlan()
            ?: $workspace?->owner?->effectiveSubscription()?->plan;

        return (bool) $plan?->hasFeature('white_label');
    }

    public function hasEnabledAiChatbot(): bool
    {
        if (! $this->ai_enabled) {
            return false;
        }

        $chatbotId = $this->channelAccount?->meta_json['ai_chatbot_id'] ?? $this->ai_chatbot_id;

        return $chatbotId
            && AiChatbot::query()
                ->whereKey($chatbotId)
                ->where('workspace_id', $this->workspace_id)
                ->where('enabled', true)
                ->exists();
    }

    /**
     * Public, compact team presence. Email addresses and internal IDs are never
     * exposed to the embedded widget.
     *
     * @return array{count: int, members: array<int, array{name: string, initial: string, avatar_url: ?string}>}
     */
    private function availableTeam(): array
    {
        $members = User::query()
            ->where('workspace_id', $this->workspace_id)
            ->where('status', User::STATUS_ACTIVE)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$this->workspace?->owner_id ?? 0])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar']);

        return [
            'count' => $members->count(),
            'members' => $members->take(3)->map(function (User $member): array {
                $name = trim((string) $member->name) ?: 'Team';
                $firstName = preg_split('/\s+/u', $name)[0] ?? $name;

                return [
                    'name' => $firstName,
                    'initial' => mb_strtoupper(mb_substr($firstName, 0, 1)),
                    'avatar_url' => $member->avatarUrl(),
                ];
            })->values()->all(),
        ];
    }

    /** Public theming/config surfaced to the embed script + widget UI. */
    public function publicConfig(): array
    {
        $avatarUrl = $this->avatar_url;
        if (! $avatarUrl || str_contains($avatarUrl, 'cerqle-icon-white-bg') || str_contains($avatarUrl, 'wisperbot')) {
            $avatarUrl = url('/cerqle-logo-transparent.svg');
        }

        $launcherLogoUrl = $this->launcher_logo_url;
        if (! $launcherLogoUrl || str_contains($launcherLogoUrl, 'cerqle-icon-white-bg') || str_contains($launcherLogoUrl, 'wisperbot')) {
            $launcherLogoUrl = url('/cerqle-logo-transparent.svg');
        }

        return [
            'key' => $this->widget_key,
            'title' => $this->title ?: 'Chat with us',
            'subtitle' => $this->subtitle ?: 'We typically reply in a few minutes',
            'welcome_message' => $this->welcome_message ?: 'Hi there 👋 How can we help?',
            'agent_name' => $this->agent_name ?: 'Support',
            'avatar_url' => $avatarUrl,
            'primary_color' => $this->primary_color ?: '#ff762e',
            'position' => $this->position ?: 'bottom_right',
            'launcher_text' => $this->launcher_text,
            // Every plan can use its own brand in the embedded widget. Existing
            // widgets retain the familiar Cerqle fallback until edited.
            'footer_company_name' => $this->footer_company_name ?: 'Cerqle',
            // The product icon remains the default for every free widget.
            // A custom launcher mark is only exposed for white-label plans.
            'launcher_logo_url' => $launcherLogoUrl,
            'ai_enabled' => $this->hasEnabledAiChatbot(),
            'available_team' => $this->availableTeam(),
            'require_prechat' => (bool) $this->require_prechat,
            'prechat_fields' => $this->prechat_fields ?: ['name', 'email'],
            'offline_message' => $this->offline_message,
            'realtime' => app(\App\Services\PusherPublicConfig::class)->widget(),
        ];
    }
}
