<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SmtpConfiguration;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Broadcasting\Services\CampaignStepService;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Broadcasting\Services\SmsCampaignCapacityService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\SegmentResolver;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\Mail\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $campaigns = Campaign::where('workspace_id', $workspaceId)
            ->when($request->channel, fn ($q) => $q->where('channel', $request->channel))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Broadcasting/Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => $request->only('channel', 'status'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Broadcasting/Campaigns/Wizard', $this->wizardProps($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        $validated = $this->validateCampaign($request);

        $steps = $validated['delivery_steps'] ?? null;
        unset($validated['delivery_steps']);
        $campaign = Campaign::create(array_merge($validated, [
            'workspace_id' => $workspaceId,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]));
        app(CampaignStepService::class)->sync($campaign, $steps);

        return redirect()->route('client.campaigns.show', $campaign)->with('success', 'Campaign created.');
    }

    /**
     * POST /campaigns/draft
     * Saves (or updates) a minimal draft after step 1 of the wizard.
     * Returns JSON so the wizard can stay on the page and continue.
     */
    /**
     * POST /campaigns/draft
     * Upserts a draft campaign with whatever fields are available at the current wizard step.
     * Returns JSON so the wizard can stay on the page without a full redirect.
     */
    public function storeDraft(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        // Hard cap for the validation rule. The actual per-step cap is
        // enforced in CampaignStepService::maxRateForStep (step 1 is the
        // safety check, capped at the safety rate; later steps can climb up
        // to the configured provider/platform ceiling).
        $platformRate = max(1, (int) config('broadcasting.sms.platform_rate_per_second', 180));

        $validated = $request->validate([
            'uuid' => ['nullable', 'string', 'uuid'],
            'name' => ['required', 'string', 'max:128'],
            'channel' => ['required', 'in:whatsapp,sms'],
            'whatsapp_phone_number_id' => ['nullable', 'string'],
            'sms_provider' => ['nullable', 'string', 'in:'.implode(',', SmsProviderController::CLIENT_VISIBLE_PROVIDERS)],
            'audience_type' => ['nullable', 'in:segment,contact_list,tag,csv'],
            'audience_ref' => ['nullable', 'string'],
            'template_ref' => ['nullable', 'array'],
            'payload_json' => ['nullable', 'array'],
            'schedule_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'delivery_steps' => ['nullable', 'array', 'max:10'],
            'delivery_steps.*.name' => ['required', 'string', 'max:80'],
            'delivery_steps.*.recipient_limit' => ['nullable', 'integer', 'min:1'],
            'delivery_steps.*.delay_after_previous_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'delivery_steps.*.rate_per_second' => ['required', 'integer', 'min:1', 'max:'.$platformRate],
        ]);

        $fields = array_filter([
            'name' => $validated['name'],
            'channel' => $validated['channel'],
            'whatsapp_phone_number_id' => $validated['whatsapp_phone_number_id'] ?? null,
            'sms_provider' => $validated['sms_provider'] ?? null,
            'audience_type' => $validated['audience_type'] ?? null,
            'audience_ref' => $validated['audience_ref'] ?? null,
            'template_ref' => $validated['template_ref'] ?? null,
            'payload_json' => $validated['payload_json'] ?? null,
            'schedule_at' => $validated['schedule_at'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($validated['uuid'])) {
            $existing = Campaign::where('workspace_id', $workspaceId)
                ->where('uuid', $validated['uuid'])
                ->where('status', 'draft')
                ->first();

            if ($existing) {
                $existing->update($fields);
                app(CampaignStepService::class)->sync($existing, $validated['delivery_steps'] ?? null);

                return response()->json(['uuid' => $existing->uuid]);
            }
        }

        $campaign = Campaign::create(array_merge($fields, [
            'workspace_id' => $workspaceId,
            'audience_type' => $fields['audience_type'] ?? 'segment',
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]));
        app(CampaignStepService::class)->sync($campaign, $validated['delivery_steps'] ?? null);

        return response()->json(['uuid' => $campaign->uuid]);
    }

    public function edit(Request $request, Campaign $campaign): Response
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['draft', 'queued', 'paused', 'safety_paused'], true), 422, 'Only draft, queued, paused, or safety-paused campaigns can be edited.');

        $campaign->load('steps');

        return Inertia::render('Broadcasting/Campaigns/Edit', array_merge(
            $this->wizardProps($request),
            ['campaign' => array_merge($campaign->only(
                'id', 'uuid', 'name', 'channel', 'whatsapp_phone_number_id', 'sms_provider', 'audience_type', 'audience_ref',
                'template_ref', 'payload_json', 'schedule_at', 'timezone', 'status',
            ), ['steps' => $campaign->steps])],
        ));
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['draft', 'queued', 'paused', 'safety_paused'], true), 422, 'Only draft, queued, paused, or safety-paused campaigns can be edited.');

        $validated = $this->validateCampaign($request);
        $steps = $validated['delivery_steps'] ?? null;
        unset($validated['delivery_steps']);
        $this->assertPreparedAudienceIsUnchanged($campaign, $validated);
        $campaign->update($validated);
        app(CampaignStepService::class)->sync($campaign, $steps);

        return redirect()->route('client.campaigns.show', $campaign)->with('success', 'Campaign updated.');
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        $this->authorise($request, $campaign);

        // SMS totals also normalize older successful "sent" rows as Delivered,
        // so recalculate whenever an SMS campaign is opened.
        if ($campaign->channel === 'sms' || in_array($campaign->status, [
            'queued', 'waiting_capacity', 'preparing', 'sending', 'retrying', 'paused', 'safety_paused',
        ], true)) {
            $campaign->updateTotals();
            $campaign->refresh();
        }

        $campaign->loadCount('recipients')->load('steps');

        $recipientStats = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $sample = CampaignRecipient::where('campaign_id', $campaign->id)
            ->with(['contact:id,first_name,last_name,phone_e164,email'])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return Inertia::render('Broadcasting/Campaigns/Show', [
            'campaign' => $campaign,
            'stats' => $recipientStats,
            'sample' => $sample,
            'reportUrl' => route('client.reports.campaigns.show', $campaign->uuid),
        ]);
    }

    public function launch(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['draft', 'paused', 'safety_paused'], true), 422, 'Cannot launch this campaign.');

        $patch = ['status' => 'queued'];

        // Only override schedule_at if the request explicitly carries one.
        // Sending an empty string explicitly means "send immediately now".
        if ($request->has('schedule_at')) {
            $value = $request->input('schedule_at');
            $patch['schedule_at'] = filled($value) ? $value : null;
        }

        if ($campaign->channel === 'whatsapp') {
            $this->assertWhatsAppCampaignReady($campaign);
        }

        $campaign->update($patch);
        $campaign->refresh();

        // Only kick the job immediately when there is no future schedule.
        // Future-scheduled campaigns are picked up by LaunchScheduledCampaignsJob.
        if (! $campaign->schedule_at || $campaign->schedule_at->isPast()) {
            LaunchCampaignJob::dispatch($campaign->id)->onQueue('broadcast');
        }

        UsageMeter::track($campaign->workspace_id, 'campaigns');

        return back()->with('success', 'Campaign launched.');
    }

    public function pause(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['queued', 'preparing', 'sending', 'retrying'], true), 422, 'Only active campaigns can be paused.');
        $campaign->update(['status' => 'paused']);

        return back()->with('success', 'Campaign paused.');
    }

    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        if ($campaign->channel === 'sms') {
            app(SmsCampaignCapacityService::class)->release($campaign);
        }
        $campaign->delete();

        return redirect()->route('client.campaigns.index')->with('success', 'Campaign deleted.');
    }

    /**
     * POST /campaigns/audience-preview
     * Returns the matching contact count for an audience selection.
     */
    public function audiencePreview(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'audience_type' => ['required', 'in:segment,contact_list,tag,csv'],
            'audience_ref' => ['nullable', 'string'],
            'channel' => ['required', 'in:whatsapp,sms'],
        ]);

        $query = $this->audienceQueryForPreview(
            $workspaceId,
            $validated['audience_type'],
            $validated['audience_ref'] ?? null,
        );

        $totalMatched = (clone $query)->count('contacts.id');

        $optInColumn = match ($validated['channel']) {
            'whatsapp' => 'opt_in_whatsapp',
            'sms' => 'opt_in_sms',
        };

        $deliverable = 0;
        $sample = [];

        if ($totalMatched > 0) {
            $deliverableQuery = (clone $query)
                ->where($optInColumn, true);

            $deliverableQuery->whereNotNull('phone_e164')->where('phone_e164', '!=', '');

            $deliverable = (clone $deliverableQuery)->count('contacts.id');
            $sample = $deliverableQuery->limit(5)
                ->get(['id', 'first_name', 'last_name', 'phone_e164', 'email']);
        }

        return response()->json([
            'matched' => $totalMatched,
            'deliverable' => $deliverable,
            'sample' => $sample,
        ]);
    }

    /**
     * POST /campaigns/{campaign}/test-send
     * Sends a one-off message to a single phone/email using this campaign's content.
     */
    public function testSend(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorise($request, $campaign);

        $validated = $request->validate([
            'phone_e164' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        if (empty($validated['phone_e164']) && empty($validated['email'])) {
            return response()->json(['error' => 'Provide either a phone or email to test.'], 422);
        }

        // Strip whitespace/dashes — pass the number through as-is so each SMS
        // driver can normalise for its own provider (some BD providers expect
        // local format 01XXXXXXXXX, not the +880… international prefix).
        if (! empty($validated['phone_e164'])) {
            $validated['phone_e164'] = preg_replace('/[\s\-()]/', '', $validated['phone_e164']);
        }

        $personalizer = app(CampaignPersonalizer::class);
        $user = $request->user();

        // Build a synthetic Contact with the user's data so personalization tokens render meaningfully.
        $contact = new Contact([
            'workspace_id' => $campaign->workspace_id,
            'phone_e164' => $validated['phone_e164'] ?? null,
            'email' => $validated['email'] ?? null,
            'first_name' => $user->name ? explode(' ', $user->name)[0] : 'Test',
            'last_name' => $user->name && str_contains($user->name, ' ')
                ? trim(substr($user->name, strpos($user->name, ' ') + 1))
                : 'User',
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => true,
        ]);

        try {
            $messageId = match ($campaign->channel) {
                'whatsapp' => $this->testSendWhatsApp($campaign, $contact, $personalizer),
                'sms' => $this->testSendSms($campaign, $contact, $personalizer),
                'email' => $this->testSendEmail($campaign, $contact, $personalizer),
            };

            return response()->json([
                'ok' => true,
                'message_id' => $messageId,
                'channel' => $campaign->channel,
            ]);
        } catch (\Throwable $e) {
            // Log full details server-side; return a sanitised message to the client
            // so SMTP credentials, API keys, and internal paths are not disclosed.
            Log::channel('json')->warning('campaign.test_send.failed', [
                'campaign_id' => $campaign->id,
                'channel' => $campaign->channel,
                'error' => $e->getMessage(),
            ]);

            $safe = match (true) {
                str_contains($e->getMessage(), 'No WhatsApp') => $e->getMessage(),
                str_contains($e->getMessage(), 'Pick a WhatsApp') => $e->getMessage(),
                str_contains($e->getMessage(), 'Phone is required') => $e->getMessage(),
                str_contains($e->getMessage(), 'Email is required') => $e->getMessage(),
                str_contains($e->getMessage(), 'SMS body is empty') => $e->getMessage(),
                str_contains($e->getMessage(), 'empty after personalization') => $e->getMessage(),
                default => 'Send failed. Check your channel configuration and try again.',
            };

            return response()->json(['error' => $safe], 500);
        }
    }

    private function authorise(Request $request, Campaign $campaign): void
    {
        $workspaceId = $this->workspaceId($request);
        abort_unless((int) $campaign->workspace_id === (int) $workspaceId, 403);
    }

    private function assertPreparedAudienceIsUnchanged(Campaign $campaign, array $validated): void
    {
        if (! $campaign->recipients()->exists()) {
            return;
        }

        foreach (['channel', 'sms_provider', 'audience_type', 'audience_ref'] as $field) {
            if (array_key_exists($field, $validated) && (string) $validated[$field] !== (string) $campaign->{$field}) {
                abort(422, 'Channel and audience cannot change after campaign recipients have been prepared.');
            }
        }
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function validateCampaign(Request $request): array
    {
        // Hard cap for the validation rule. The actual per-step cap is
        // enforced in CampaignStepService::maxRateForStep (step 1 is the
        // safety check, capped at the safety rate; later steps can climb up
        // to the configured provider/platform ceiling).
        $platformRate = max(1, (int) config('broadcasting.sms.platform_rate_per_second', 180));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'channel' => ['required', 'in:whatsapp,sms'],
            'whatsapp_phone_number_id' => ['nullable', 'string'],
            'sms_provider' => ['nullable', 'string', 'in:'.implode(',', SmsProviderController::CLIENT_VISIBLE_PROVIDERS)],
            'audience_type' => ['required', 'in:segment,contact_list,tag,csv'],
            'audience_ref' => ['nullable', 'string'],
            'template_ref' => ['nullable', 'array'],
            'payload_json' => ['nullable', 'array'],
            'schedule_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'delivery_steps' => ['nullable', 'array', 'max:10'],
            'delivery_steps.*.name' => ['required', 'string', 'max:80'],
            'delivery_steps.*.recipient_limit' => ['nullable', 'integer', 'min:1'],
            'delivery_steps.*.delay_after_previous_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'delivery_steps.*.rate_per_second' => ['required', 'integer', 'min:1', 'max:'.$platformRate],
        ]);

        if ($validated['channel'] === 'sms') {
            if (blank($validated['sms_provider'] ?? null)) {
                abort(422, 'Choose a configured SMS provider for this campaign.');
            }

            try {
                SmsDriverManager::resolveForWorkspace($this->workspaceId($request), $validated['sms_provider']);
            } catch (\Throwable) {
                abort(422, 'This SMS provider is not configured. Configure it in SMS Gateways first.');
            }
        }

        return $validated;
    }

    /**
     * Build the props the wizard / edit page need.
     */
    private function assertWhatsAppCampaignReady(Campaign $campaign): void
    {
        $client = $campaign->whatsapp_phone_number_id
            ? CloudApiClient::forPhoneNumber($campaign->whatsapp_phone_number_id, $campaign->workspace_id)
            : CloudApiClient::forWorkspace($campaign->workspace_id);

        if (! $client) {
            abort(422, 'WhatsApp is not ready: connect a WABA on Channel Setup and sync at least one phone number.');
        }

        $tpl = $campaign->template_ref ?? [];
        $name = $tpl['name'] ?? '';
        if ($name === '') {
            abort(422, 'Select an approved WhatsApp template before launching.');
        }

        $approved = WhatsappTemplate::where('workspace_id', $campaign->workspace_id)
            ->where('name', $name)
            ->where('language', $tpl['language'] ?? 'en')
            ->where('status', 'APPROVED')
            ->exists();

        if (! $approved) {
            abort(422, 'Template "'.$name.'" is not APPROVED. Sync templates from Meta on the Templates page, then try again.');
        }
    }

    private function wizardProps(Request $request): array
    {
        $workspaceId = $this->workspaceId($request);

        $whatsappTemplates = WhatsappTemplate::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->orderBy('language')
            ->get(['id', 'waba_id', 'name', 'language', 'status', 'category', 'components'])
            ->sortBy(fn ($t) => match ($t->status) {
                'APPROVED' => 0,
                'PENDING' => 1,
                'PAUSED' => 2,
                default => 3,
            })
            ->values();

        $segments = Segment::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'contact_count']);

        $tags = ContactTag::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $whatsappPhoneNumbers = WhatsappBusinessAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->with('phoneNumbers')
            ->get()
            ->flatMap(fn ($waba) => $waba->phoneNumbers->map(fn ($p) => [
                'phone_number_id' => $p->phone_number_id,
                'display_phone' => $p->display_phone,
                'verified_name' => $p->verified_name,
                'waba_id' => $waba->waba_id,
            ]))
            ->values();

        return [
            'whatsappTemplates' => $whatsappTemplates,
            'whatsappPhoneNumbers' => $whatsappPhoneNumbers,
            'segments' => $segments,
            'tags' => $tags,
            'contactTokens' => CampaignPersonalizer::availableContactTokens(),
            'smsDeliveryLimits' => $this->smsDeliveryLimits(),
            'smsProviders' => $this->configuredSmsProviders($workspaceId),
        ];
    }

    /** @return array{safetyRate: int, bulkRate: int, speedOptions: array<int, int>} */
    private function smsDeliveryLimits(): array
    {
        $steps = app(CampaignStepService::class);
        $safetyRate = $steps->maxRateForStep(1);
        $bulkRate = $steps->maxRateForStep(2);
        $options = array_values(array_unique(array_filter(
            [1, 2, 3, 4, 5, 10, 25, 50, 75, 100, 125, 150, 160, 180, $bulkRate],
            fn (int $rate) => $rate <= $bulkRate,
        )));
        sort($options);

        return [
            'safetyRate' => $safetyRate,
            'bulkRate' => $bulkRate,
            'speedOptions' => $options,
        ];
    }

    /** @return array<int, array{provider: string, label: string, throughputTps: int, default: bool}> */
    private function configuredSmsProviders(int $workspaceId): array
    {
        return SmsProviderConfig::where('workspace_id', $workspaceId)
            ->whereIn('provider', SmsProviderController::CLIENT_VISIBLE_PROVIDERS)
            ->get()
            ->filter(fn (SmsProviderConfig $config) => ! empty($config->credentials))
            ->map(function (SmsProviderConfig $config): array {
                $resolved = SmsDriverManager::resolvedFromConfig($config);

                return [
                    'provider' => $config->provider,
                    'label' => SmsProviderController::LABELS[$config->provider] ?? ucfirst($config->provider),
                    'throughputTps' => $resolved->throughputTps,
                    'default' => (bool) $config->default,
                ];
            })
            ->sortByDesc('default')
            ->values()
            ->all();
    }

    /**
     * Mirror of the audience resolution used by LaunchCampaignJob, scoped to a single workspace.
     *
     * @return array<int, int>
     */
    private function resolveAudienceForPreview(int $workspaceId, string $type, ?string $ref): array
    {
        return match ($type) {
            'segment' => $this->resolveSegmentForPreview($workspaceId, $ref),
            'tag' => $this->resolveTagForPreview($workspaceId, $ref),
            'contact_list' => Contact::where('workspace_id', $workspaceId)->pluck('id')->all(),
            'csv' => [],
            default => [],
        };
    }

    private function audienceQueryForPreview(int $workspaceId, string $type, ?string $ref)
    {
        return match ($type) {
            'segment' => $this->segmentQueryForPreview($workspaceId, $ref),
            'tag' => Contact::where('workspace_id', $workspaceId)
                ->whereHas('tags', fn ($q) => $q->where('contact_tags.id', $ref)),
            'contact_list' => Contact::where('workspace_id', $workspaceId),
            default => Contact::whereRaw('1 = 0'),
        };
    }

    private function segmentQueryForPreview(int $workspaceId, ?string $ref)
    {
        if (! $ref) {
            return Contact::whereRaw('1 = 0');
        }
        $segment = Segment::where('workspace_id', $workspaceId)->find($ref);

        return $segment
            ? app(SegmentResolver::class)->query($segment)
            : Contact::whereRaw('1 = 0');
    }

    /** @return array<int, int> */
    private function resolveSegmentForPreview(int $workspaceId, ?string $ref): array
    {
        if (! $ref) {
            return [];
        }
        $segment = Segment::where('workspace_id', $workspaceId)->find($ref);
        if (! $segment) {
            return [];
        }
        if ($segment->type === 'static') {
            return $segment->contacts()->pluck('contacts.id')->all();
        }

        return app(SegmentResolver::class)->query($segment)->pluck('id')->all();
    }

    /** @return array<int, int> */
    private function resolveTagForPreview(int $workspaceId, ?string $ref): array
    {
        if (! $ref) {
            return [];
        }

        return Contact::where('workspace_id', $workspaceId)
            ->whereHas('tags', fn ($q) => $q->where('contact_tags.id', $ref))
            ->pluck('id')
            ->all();
    }

    private function testSendWhatsApp(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): string
    {
        if (empty($contact->phone_e164)) {
            throw new \RuntimeException('Phone is required for a WhatsApp test send.');
        }

        $client = $campaign->whatsapp_phone_number_id
            ? CloudApiClient::forPhoneNumber($campaign->whatsapp_phone_number_id, $campaign->workspace_id)
            : CloudApiClient::forWorkspace($campaign->workspace_id);
        if (! $client) {
            throw new \RuntimeException('No WhatsApp client configured for this workspace.');
        }

        $tpl = $campaign->template_ref ?? [];
        $name = $tpl['name'] ?? '';
        $language = $tpl['language'] ?? 'en';
        $components = is_array($tpl['components'] ?? null) ? $tpl['components'] : [];

        if ($name === '') {
            throw new \RuntimeException('Pick a WhatsApp template before sending a test.');
        }

        $phone = $contact->phone_e164;
        if (! str_starts_with($phone, '+')) {
            $phone = '+'.$phone;
        }

        $rendered = $personalizer->renderTemplateComponents($components, $contact);
        $resp = $client->sendTemplate($phone, $name, $language, $rendered);

        if (! $resp->successful()) {
            throw new \RuntimeException('WhatsApp send failed: '.$resp->body());
        }

        return $resp->json('messages.0.id', '');
    }

    private function testSendSms(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): string
    {
        if (empty($contact->phone_e164)) {
            throw new \RuntimeException('Phone is required for an SMS test send.');
        }

        $body = $personalizer->renderText($campaign->payload_json['body'] ?? '', $contact);
        if (trim($body) === '') {
            throw new \RuntimeException('SMS body is empty after personalization.');
        }

        $driver = SmsDriverManager::forWorkspace($campaign->workspace_id);
        $result = $driver->send($contact->phone_e164, $body);

        if (! $result->success) {
            throw new \RuntimeException($result->error);
        }

        return $result->messageId;
    }

    private function testSendEmail(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): string
    {
        if (empty($contact->email)) {
            throw new \RuntimeException('Email is required for an email test send.');
        }

        $payload = $campaign->payload_json ?? [];
        $subject = $personalizer->renderText('[TEST] '.($payload['subject'] ?? 'No subject'), $contact);
        $body = $personalizer->renderText($payload['body'] ?? '', $contact);
        $fromEmail = filled($payload['from_email'] ?? '') ? $payload['from_email'] : null;
        $fromName = filled($payload['from_name'] ?? '') ? $payload['from_name'] : null;
        $replyTo = filled($payload['reply_to'] ?? '') ? $payload['reply_to'] : null;

        $smtp = WorkspaceSmtpConfig::forWorkspace($campaign->workspace_id)
            ?? SmtpConfiguration::getActive();

        if ($smtp) {
            app(MailService::class)->sendRaw(
                $smtp, $contact->email, $subject, $body, [], $fromEmail, $fromName, $replyTo
            );
        } else {
            Mail::html($body, function ($m) use ($contact, $subject, $fromEmail, $fromName, $replyTo) {
                $m->to($contact->email, $contact->full_name)->subject($subject);
                if ($fromEmail) {
                    $m->from($fromEmail, $fromName ?: null);
                }
                if ($replyTo) {
                    $m->replyTo($replyTo);
                }
            });
        }

        return 'email-test:'.uniqid();
    }
}
