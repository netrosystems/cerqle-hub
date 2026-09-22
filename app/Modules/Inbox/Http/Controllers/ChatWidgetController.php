<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\ChatWidgetAvatarProcessor;
use App\Modules\Inbox\Services\WidgetAiAvailability;
use App\Modules\Shared\Models\ChannelAccount;
use App\Services\ChannelPlanLimitService;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-scoped CRUD for website live-chat widgets. Each widget owns one
 * `webchat` channel_account so its conversations land in the omnichannel inbox;
 * AI availability is widget-owned and revisioned; channel metadata is retained
 * for compatibility, never used to bypass widget availability.
 */
class ChatWidgetController extends Controller
{
    public function __construct(
        private readonly StorageManager $storageManager,
        private readonly ChatWidgetAvatarProcessor $avatarProcessor,
    ) {}

    public function index(Request $request): Response
    {
        $widgets = ChatWidget::where('workspace_id', $this->workspaceId($request))->latest()->get();

        return Inertia::render('Chat/Widgets/Index', [
            'widgets' => $widgets,
            'aiAvailability' => $widgets->mapWithKeys(fn ($widget) => [$widget->id => app(WidgetAiAvailability::class)->publicState($widget)]),
            'embedBase' => rtrim(url('/'), '/'),
            'defaultPrimaryColor' => self::defaultPrimaryColor(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Chat/Widgets/Create', [
            'chatbots' => $this->chatbots($request),
            'aiTimezone' => config('app.timezone', 'UTC'),
            'canUseCustomLauncherLogo' => $this->canUseCustomLauncherLogo($request),
            'defaultPrimaryColor' => self::defaultPrimaryColor(),
        ]);
    }

    /**
     * A new widget starts in Cerqle's own colour; the client changes it from
     * there. The value comes from config rather than a literal in three files,
     * so rebranding is one edit.
     */
    public static function defaultPrimaryColor(): string
    {
        return (string) config('saas.branding.primary_color', '#8F5FA7');
    }

    public function edit(Request $request, ChatWidget $chatWidget): Response
    {
        $this->assertOwner($request, $chatWidget);

        return Inertia::render('Chat/Widgets/Edit', [
            'widget' => $chatWidget,
            'aiTimezone' => config('app.timezone', 'UTC'),
            'chatbots' => $this->chatbots($request),
            'embedBase' => rtrim(url('/'), '/'),
            // Server-side only (the model hides identity_secret); shown to the
            // client so they can HMAC-sign logged-in users on their backend.
            'identitySecret' => $chatWidget->identity_secret,
            'canUseCustomLauncherLogo' => $this->canUseCustomLauncherLogo($request),
            'defaultPrimaryColor' => self::defaultPrimaryColor(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $workspaceId = $this->workspaceId($request);
        $workspace = Workspace::findOrFail($workspaceId);
        DB::transaction(function () use ($request, $workspace, $workspaceId, $data) {
            $limits = app(ChannelPlanLimitService::class);
            $limits->ensureCapacity($limits->usage($workspace, 'website_widgets', true));
            $data = $this->applyLauncherLogo($request, $data);
            $data = $this->applyAvatar($request, $data);
            $channelAccount = ChannelAccount::create([
                'workspace_id' => $workspaceId,
                'channel' => 'webchat',
                'status' => 'active',
                'display_name' => $data['name'] ?: 'Website chat',
                'meta_json' => $this->metaFor($data),
            ]);

            ChatWidget::create(array_merge($data, [
                'workspace_id' => $workspaceId,
                'channel_account_id' => $channelAccount->id,
            ]));
        });

        return redirect()->route('client.inbox.chat-widgets.index')->with('success', 'Chat widget created.');
    }

    public function update(Request $request, ChatWidget $chatWidget): RedirectResponse
    {
        $this->assertOwner($request, $chatWidget);
        DB::transaction(function () use ($request, $chatWidget) {
            $locked = ChatWidget::where('workspace_id', $this->workspaceId($request))->whereKey($chatWidget->id)->lockForUpdate()->firstOrFail();
            if ($request->has('ai_revision') && (int) $request->input('ai_revision') !== $locked->ai_revision) {
                throw ValidationException::withMessages(['ai_revision' => 'This widget changed. Reload before saving.']);
            }
            $data = $this->validated($request, $locked);
            $data = $this->applyLauncherLogo($request, $data, $locked);
            $data = $this->applyAvatar($request, $data, $locked);
            $data['ai_revision'] = $locked->ai_revision + 1;
            $locked->update($data);
            $locked->channelAccount?->update([
                'display_name' => $data['name'] ?: 'Website chat',
                'meta_json' => array_merge($locked->channelAccount->meta_json ?? [], ['ai_chatbot_id' => $data['ai_enabled'] ? $data['ai_chatbot_id'] : null]),
            ]);
        });

        return back()->with('success', 'Widget updated.');
    }

    /**
     * Flip the widget on or off on its own.
     *
     * Separate from update() because it is a different kind of action: it takes
     * effect the moment it is clicked rather than waiting for Save, and it must
     * not drag the rest of an unsaved form along with it.
     */
    public function toggleEnabled(Request $request, ChatWidget $chatWidget): RedirectResponse
    {
        $this->assertOwner($request, $chatWidget);
        $enabled = $request->boolean('enabled');
        $chatWidget->update(['enabled' => $enabled]);

        return back()->with('success', $enabled ? 'Widget turned on.' : 'Widget turned off.');
    }

    public function destroy(Request $request, ChatWidget $chatWidget): RedirectResponse
    {
        $this->assertOwner($request, $chatWidget);

        // Keep the channel_account (mark inactive) so past conversations still
        // resolve in the inbox — just deactivate and remove the widget config.
        $chatWidget->channelAccount?->update(['status' => 'inactive']);
        $this->deleteAvatar($chatWidget);
        $this->deleteLauncherLogo($chatWidget);
        $chatWidget->delete();

        return redirect()->route('client.inbox.chat-widgets.index')->with('success', 'Widget deleted.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ChatWidget $widget = null): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'title' => ['nullable', 'string', 'max:128'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
            'agent_name' => ['nullable', 'string', 'max:64'],
            'avatar_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'remove_avatar' => ['nullable', 'boolean'],
            // A colour that is not a hex reaches the embed script as-is and
            // renders as nothing, so the widget loses its branding on the
            // client's own site. Rejecting it here is the only place it can be
            // caught before that happens.
            'primary_color' => ['nullable', 'string', 'max:16', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'position' => ['required', 'in:bottom_right,bottom_left'],
            'launcher_text' => ['nullable', 'string', 'max:64'],
            'footer_company_name' => ['nullable', 'string', 'max:128'],
            'launcher_logo' => ['nullable', 'image', 'mimes:png', 'extensions:png', 'max:2048'],
            'remove_launcher_logo' => ['nullable', 'boolean'],
            'ai_chatbot_id' => ['nullable', 'integer'],
            'ai_mode' => ['sometimes', 'required', 'in:off,permanent,scheduled'],
            'ai_timezone' => ['sometimes', 'required', 'timezone:all'],
            'ai_weekly_hours' => ['sometimes', 'array'],
            'ai_revision' => ['sometimes', 'integer', 'min:1'],
            'prechat_fields' => ['nullable', 'array'],
            'offline_message' => ['nullable', 'string', 'max:512'],
            'allowed_domains' => ['nullable', 'array'],
            'working_hours_json' => ['nullable', 'array'],
        ], [
            'primary_color.regex' => 'Enter a hex colour such as #8F5FA7.',
        ]);

        // Coerce booleans explicitly (Inertia may omit unchecked toggles).
        $data['ai_mode'] = $data['ai_mode'] ?? ($request->boolean('ai_enabled') ? 'permanent' : 'off');
        $data['ai_enabled'] = $data['ai_mode'] !== 'off';
        $data['ai_chatbot_id'] = array_key_exists('ai_chatbot_id', $data) ? $data['ai_chatbot_id'] : $widget?->ai_chatbot_id;
        if ($data['ai_chatbot_id'] && ! AiChatbot::where('workspace_id', $this->workspaceId($request))->whereKey($data['ai_chatbot_id'])->when($data['ai_enabled'], fn ($q) => $q->where('enabled', true))->exists()) {
            throw ValidationException::withMessages(['ai_chatbot_id' => 'Select an enabled chatbot from this workspace.']);
        }
        if ($data['ai_enabled'] && ! $data['ai_chatbot_id']) {
            throw ValidationException::withMessages(['ai_chatbot_id' => 'Select a chatbot.']);
        }
        $data['ai_timezone'] = $data['ai_timezone'] ?? $widget?->ai_timezone ?? config('app.timezone', 'UTC');
        $data['ai_weekly_hours'] = app(WidgetAiAvailability::class)->validateHours($data['ai_weekly_hours'] ?? $widget?->ai_weekly_hours ?? app(WidgetAiAvailability::class)->defaults(), $data['ai_timezone'], $data['ai_mode'] === 'scheduled');
        unset($data['ai_revision']);
        // An empty colour field means "use ours", not "store nothing": the
        // column is NOT NULL, and an empty string arrives here as null because
        // of ConvertEmptyStringsToNull, so without this the save is a 500.
        if (array_key_exists('primary_color', $data) && ($data['primary_color'] ?? '') === '') {
            $data['primary_color'] = self::defaultPrimaryColor();
        }
        $data['require_prechat'] = $request->boolean('require_prechat');
        $data['identity_verification'] = $request->boolean('identity_verification');
        // On/off lives in its own control, not in this form, so an absent value
        // means "leave it as it is". Defaulting to true here would silently
        // switch a disabled widget back on every time its settings were saved.
        $data['enabled'] = $request->has('enabled')
            ? $request->boolean('enabled')
            : ($widget?->enabled ?? true);

        unset(
            $data['avatar_image'],
            $data['remove_avatar'],
            $data['launcher_logo'],
            $data['remove_launcher_logo'],
        );

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function applyLauncherLogo(Request $request, array $data, ?ChatWidget $widget = null): array
    {
        $canUseCustomLogo = $this->canUseCustomLauncherLogo($request);
        $hasUpload = $request->hasFile('launcher_logo');

        if ($hasUpload && ! $canUseCustomLogo) {
            throw ValidationException::withMessages([
                'launcher_logo' => 'Choose a paid plan to upload a custom launcher icon.',
            ]);
        }

        // A downgrade never continues serving a paid white-label asset.
        if (! $canUseCustomLogo || $request->boolean('remove_launcher_logo')) {
            $this->deleteLauncherLogo($widget);

            return array_merge($data, ['launcher_logo_path' => null, 'launcher_logo_disk' => null]);
        }

        if (! $hasUpload) {
            return $data;
        }

        $this->deleteLauncherLogo($widget);
        $file = $request->file('launcher_logo');
        $path = $this->storageManager->prefixedPath('widget-launchers/'.Str::uuid().'.png');
        $disk = $this->storageManager->disk();
        if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw ValidationException::withMessages([
                'launcher_logo' => 'The logo could not be uploaded to the configured storage provider.',
            ]);
        }

        return array_merge($data, [
            'launcher_logo_path' => $path,
            'launcher_logo_disk' => $this->storageManager->diskName(),
        ]);
    }

    private function deleteLauncherLogo(?ChatWidget $widget): void
    {
        if (! $widget?->launcher_logo_path) {
            return;
        }

        $disk = $widget->launcher_logo_disk ?: $this->storageManager->diskName();
        Storage::disk($disk)->delete($widget->launcher_logo_path);
    }

    /** @param array<string, mixed> $data */
    private function applyAvatar(Request $request, array $data, ?ChatWidget $widget = null): array
    {
        if ($request->boolean('remove_avatar')) {
            $this->deleteAvatar($widget);

            return array_merge($data, [
                'avatar_url' => null,
                'avatar_path' => null,
                'avatar_disk' => null,
            ]);
        }

        if (! $request->hasFile('avatar_image')) {
            return $data;
        }

        $webp = $this->avatarProcessor->process($request->file('avatar_image'));
        $path = $this->storageManager->prefixedPath('widget-avatars/'.Str::uuid().'.webp');
        $disk = $this->storageManager->disk();

        if (! $disk->put($path, $webp, ['visibility' => 'public'])) {
            throw ValidationException::withMessages([
                'avatar_image' => 'The avatar could not be uploaded to the configured storage provider.',
            ]);
        }

        $this->deleteAvatar($widget);

        return array_merge($data, [
            'avatar_url' => null,
            'avatar_path' => $path,
            'avatar_disk' => $this->storageManager->diskName(),
        ]);
    }

    private function deleteAvatar(?ChatWidget $widget): void
    {
        if (! $widget?->avatar_path) {
            return;
        }

        $disk = $widget->avatar_disk ?: $this->storageManager->diskName();
        $this->storageManager->ensureDiskReady($disk);
        Storage::disk($disk)->delete($widget->avatar_path);
    }

    private function canUseCustomLauncherLogo(Request $request): bool
    {
        return (bool) $this->workspacePlan($request)?->hasFeature('custom_launcher_icon');
    }

    private function workspacePlan(Request $request)
    {
        $workspace = Workspace::with(['client.activeSubscription.plan', 'owner.activeSubscription.plan'])
            ->find($this->workspaceId($request));

        return $workspace?->client?->effectivePlan()
            ?: $workspace?->owner?->effectiveSubscription()?->plan;
    }

    /**
     * The webchat channel_account's meta_json. ai_chatbot_id is only set when AI
     * is enabled for compatibility. Website reply eligibility is widget-owned.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function metaFor(array $data): array
    {
        return ! empty($data['ai_enabled']) && ! empty($data['ai_chatbot_id'])
            ? ['ai_chatbot_id' => (int) $data['ai_chatbot_id']]
            : [];
    }

    private function chatbots(Request $request)
    {
        return AiChatbot::where('workspace_id', $this->workspaceId($request))
            ->where('enabled', true)
            ->get(['id', 'name']);
    }

    private function workspaceId(Request $request): int
    {
        return $request->user()->current_workspace_id ?? $request->user()->workspace_id;
    }

    private function assertOwner(Request $request, ChatWidget $widget): void
    {
        abort_unless((int) $widget->workspace_id === $this->workspaceId($request), 403);
    }
}
