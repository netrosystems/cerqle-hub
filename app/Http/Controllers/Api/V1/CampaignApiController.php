<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\CampaignRecipientResource;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Broadcasting\Services\CampaignStepService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CampaignApiController extends WorkspaceScopedController
{
    /**
     * GET /api/v1/campaigns
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Campaign::where('workspace_id', $this->workspaceId($request))->latest('id');

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return CampaignResource::collection($query->paginate(25));
    }

    /**
     * POST /api/v1/campaigns – create a draft
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'channel' => ['required', 'string', 'in:whatsapp,sms,email'],
            'audience_type' => ['nullable', 'string', 'in:segment,contact_list,tag,csv'],
            'audience_ref' => ['nullable', 'string'],
            'template_ref' => ['nullable', 'array'],
            'payload_json' => ['nullable', 'array'],
            'schedule_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'delivery_steps' => ['nullable', 'array', 'max:10'],
            'delivery_steps.*.name' => ['required', 'string', 'max:80'],
            'delivery_steps.*.recipient_limit' => ['nullable', 'integer', 'min:1'],
            'delivery_steps.*.delay_after_previous_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'delivery_steps.*.rate_per_second' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        // Default audience_type when omitted to keep DB enum happy.
        $validated['audience_type'] = $validated['audience_type'] ?? 'segment';

        $steps = $validated['delivery_steps'] ?? null;
        unset($validated['delivery_steps']);
        $campaign = Campaign::create(array_merge($validated, [
            'workspace_id' => $this->workspaceId($request),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]));
        app(CampaignStepService::class)->sync($campaign, $steps);

        return (new CampaignResource($campaign->load('steps')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/campaigns/{id} – with fresh stats
     */
    public function show(Request $request, int $id): CampaignResource|JsonResponse
    {
        $campaign = Campaign::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $campaign) {
            return response()->json(['error' => 'Campaign not found.'], 404);
        }

        // Recompute stats fresh from recipients (as specified in plan section D)
        $campaign->updateTotals();
        $campaign->refresh();

        return new CampaignResource($campaign->load('steps'));
    }

    /**
     * POST /api/v1/campaigns/{id}/launch
     */
    public function launch(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $campaign) {
            return response()->json(['error' => 'Campaign not found.'], 404);
        }

        if (! in_array($campaign->status, ['draft', 'paused', 'safety_paused'])) {
            return response()->json(['error' => 'Campaign cannot be launched from status: '.$campaign->status.'.'], 422);
        }

        $patch = ['status' => 'queued'];
        if ($request->has('schedule_at')) {
            $value = $request->input('schedule_at');
            $patch['schedule_at'] = filled($value) ? $value : null;
        }

        $campaign->update($patch);
        $campaign->refresh();

        if (! $campaign->schedule_at || $campaign->schedule_at->isPast()) {
            LaunchCampaignJob::dispatch($campaign->id)->onQueue('broadcast');
        }

        UsageMeter::track($campaign->workspace_id, 'campaigns');

        return response()->json([
            'ok' => true,
            'status' => 'queued',
            'schedule_at' => optional($campaign->schedule_at)->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/campaigns/{id}/pause
     */
    public function pause(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $campaign) {
            return response()->json(['error' => 'Campaign not found.'], 404);
        }

        if (! in_array($campaign->status, ['preparing', 'sending', 'retrying'], true)) {
            return response()->json(['error' => 'Only active campaigns can be paused.'], 422);
        }

        $campaign->update(['status' => 'paused']);

        return response()->json(['ok' => true, 'status' => 'paused']);
    }

    /**
     * GET /api/v1/campaigns/{id}/recipients
     */
    public function recipients(Request $request, int $id): AnonymousResourceCollection|JsonResponse
    {
        $campaign = Campaign::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $campaign) {
            return response()->json(['error' => 'Campaign not found.'], 404);
        }

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)
            ->latest('id')
            ->paginate(50);

        return CampaignRecipientResource::collection($recipients);
    }

    /**
     * PATCH /api/v1/campaigns/{id} – update a draft or paused campaign.
     */
    public function update(Request $request, int $id): CampaignResource|JsonResponse
    {
        $campaign = Campaign::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $campaign) {
            return response()->json(['error' => 'Campaign not found.'], 404);
        }

        if (! in_array($campaign->status, ['draft', 'paused', 'safety_paused'], true)) {
            return response()->json(['error' => 'Only draft or paused campaigns can be edited.'], 422);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'channel' => ['sometimes', 'required', 'string', 'in:whatsapp,sms,email'],
            'audience_type' => ['sometimes', 'required', 'string', 'in:segment,contact_list,tag,csv'],
            'audience_ref' => ['nullable', 'string'],
            'template_ref' => ['nullable', 'array'],
            'payload_json' => ['nullable', 'array'],
            'schedule_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'delivery_steps' => ['nullable', 'array', 'max:10'],
            'delivery_steps.*.name' => ['required', 'string', 'max:80'],
            'delivery_steps.*.recipient_limit' => ['nullable', 'integer', 'min:1'],
            'delivery_steps.*.delay_after_previous_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'delivery_steps.*.rate_per_second' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $steps = $validated['delivery_steps'] ?? null;
        unset($validated['delivery_steps']);
        if ($campaign->recipients()->exists()) {
            foreach (['channel', 'audience_type', 'audience_ref'] as $field) {
                if (array_key_exists($field, $validated) && (string) $validated[$field] !== (string) $campaign->{$field}) {
                    return response()->json([
                        'error' => 'Channel and audience cannot change after campaign recipients have been prepared.',
                    ], 422);
                }
            }
        }
        $campaign->update($validated);
        app(CampaignStepService::class)->sync($campaign, $steps);

        return new CampaignResource($campaign->refresh()->load('steps'));
    }
}
