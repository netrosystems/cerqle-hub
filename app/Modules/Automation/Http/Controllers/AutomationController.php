<?php

namespace App\Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Automation\Services\AutomationRetryPolicy;
use App\Modules\Automation\Services\WorkflowGenerator;
use App\Modules\Automation\Services\WorkflowValidator;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $automations = Automation::where('workspace_id', $wid)
            ->withCount('runs')
            ->latest()->get();

        return Inertia::render('Automation/Index', [
            'automations' => $automations,
            // Shown on the Generate button so the charge is never a surprise.
            'generateCost' => (int) config('ai.credits.rates.automation_workflow_generate', 5),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:128']]);

        $auto = Automation::create(array_merge($validated, [
            'workspace_id' => $wid,
            'status' => 'draft',
            'nodes' => [['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 50], 'data' => ['label' => 'Trigger']]],
            'edges' => [],
        ]));

        return redirect()->route('client.automations.edit', $auto->uuid)->with('success', 'Automation created.');
    }

    public function edit(Request $request, Automation $automation): Response
    {
        $this->authorise($request, $automation);
        $wid = (int) $automation->workspace_id;

        return Inertia::render('Automation/Builder', [
            'automation' => $automation,
            'resources' => $this->builderResources($wid, $automation->id),
            'generateCost' => (int) config('ai.credits.rates.automation_workflow_generate', 5),
        ]);
    }

    /**
     * Reference data the builder needs to populate node config dropdowns
     * (templates, campaigns, chatbots, sub-flows, agents, stores) plus a map of
     * which optional integrations are connected.
     *
     * @return array<string, mixed>
     */
    private function builderResources(int $workspaceId, int $currentAutomationId): array
    {
        return [
            'whatsapp_accounts' => ChannelAccount::where('workspace_id', $workspaceId)->where('channel', 'whatsapp')->where('status', 'active')->get(['id', 'display_name', 'business_account_id', 'phone_number_id']),
            // All templates (approved first) so the builder can list existing ones and
            // surface their body variables; non-approved are shown but flagged in the UI.
            'templates' => WhatsappTemplate::where('workspace_id', $workspaceId)
                ->orderByRaw("CASE WHEN status = 'APPROVED' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(['name', 'language', 'status', 'components', 'waba_id'])
                ->map(fn ($t) => [
                    'name' => $t->name,
                    'language' => $t->language,
                    'status' => $t->status,
                    'components' => $t->components,
                    'waba_id' => $t->waba_id,
                ])
                ->values(),
            'campaigns' => Campaign::where('workspace_id', $workspaceId)
                ->latest()->limit(100)->get(['id', 'name'])->values(),
            'chatbots' => AiChatbot::where('workspace_id', $workspaceId)
                ->where('enabled', true)->orderBy('name')->get(['id', 'name'])->values(),
            'subflows' => Automation::where('workspace_id', $workspaceId)
                ->where('id', '!=', $currentAutomationId)
                ->orderBy('name')->get(['uuid', 'name', 'status'])->values(),
            'agents' => User::inWorkspace($workspaceId)
                ->orderBy('name')->get(['id', 'name'])->values(),
            'stores' => EcommerceStore::where('workspace_id', $workspaceId)
                ->get(['id', 'platform', 'name'])->values(),
            'integrations' => [
                'google' => (bool) optional(IntegrationConfig::forProvider('google_workspace'))->enabled,
            ],
        ];
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        $this->authorise($request, $automation);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'status' => ['sometimes', 'in:active,paused,draft'],
            'trigger_type' => ['nullable', 'string', 'max:64'],
            'trigger_config' => ['nullable', 'array'],
            'nodes' => ['nullable', 'array'],
            'edges' => ['nullable', 'array'],
            'nodes.*' => ['array'],
            'nodes.*.id' => ['required', 'string', 'max:64'],
            'nodes.*.type' => ['required', 'string'],
            'nodes.*.data' => ['nullable', 'array'],
            'edges.*' => ['array'],
            'edges.*.source' => ['required', 'string'],
            'edges.*.target' => ['required', 'string'],
            'trigger_config.channel_account_id' => ['nullable', 'integer', 'min:1'],
            'trigger_config.keywords' => ['nullable', 'array'],
            'trigger_config.keywords.*' => ['string', 'min:1', 'max:100'],
        ]);

        if (isset($validated['nodes'])) {
            $validated['nodes'] = $this->normaliseNodes($validated['nodes'], $validated['trigger_type'] ?? $automation->trigger_type);
        }

        $workflow = array_merge($automation->only(['nodes', 'edges', 'trigger_type', 'trigger_config']), $validated);
        if (! (count($validated) === 1 && ($validated['status'] ?? '') === 'paused')) {
            app(WorkflowValidator::class)->validate($this->workspaceId($request), $workflow, ($validated['status'] ?? $automation->status) === 'active');
        }

        $automation->update($validated);

        return back()->with('success', 'Automation saved.');
    }

    public function destroy(Request $request, Automation $automation): RedirectResponse
    {
        $this->authorise($request, $automation);
        $automation->delete();

        return redirect()->route('client.automations.index')->with('success', 'Automation deleted.');
    }

    public function runs(Request $request, Automation $automation): Response
    {
        $this->authorise($request, $automation);
        $runs = AutomationRun::where('automation_id', $automation->id)
            ->with('logs')
            ->latest()->paginate(50);

        $policy = app(AutomationRetryPolicy::class);
        $runs->getCollection()->each(function (AutomationRun $run) use ($automation, $policy): void {
            $run->setRelation('automation', $automation);
            $run->setAttribute('retry', $run->status === 'failed' ? $policy->assess($run) : null);
            $run->unsetRelation('automation');
        });

        return Inertia::render('Automation/Runs', ['automation' => $automation, 'runs' => $runs]);
    }

    public function generateToken(Request $request, Automation $automation): JsonResponse
    {
        $this->authorise($request, $automation);
        $automation->update(['trigger_token' => Str::random(48)]);

        return response()->json(['trigger_token' => $automation->trigger_token]);
    }

    /**
     * Dry-run the automation and return a step-by-step trace. Tests the live builder
     * state (posted nodes/edges) when supplied, otherwise the saved version. The engine
     * simulates every action — nothing is actually sent and no data is written.
     */
    public function test(Request $request, Automation $automation): JsonResponse
    {
        $this->authorise($request, $automation);
        $validated = $request->validate([
            'nodes' => ['nullable', 'array'],
            'edges' => ['nullable', 'array'],
            'nodes.*' => ['array'],
            'nodes.*.id' => ['required', 'string', 'max:64'],
            'nodes.*.type' => ['required', 'string'],
            'nodes.*.data' => ['nullable', 'array'],
            'edges.*' => ['array'],
            'edges.*.source' => ['required', 'string'],
            'edges.*.target' => ['required', 'string'],
            'trigger_type' => ['nullable', 'string', 'max:64'],
            'trigger_config' => ['nullable', 'array'],
            'sample_message' => ['nullable', 'string', 'max:1000'],
            'sample_answer' => ['nullable', 'string', 'max:1000'],
        ]);

        $nodes = $validated['nodes'] ?? $automation->nodes ?? [];
        $edges = $validated['edges'] ?? $automation->edges ?? [];
        app(WorkflowValidator::class)->validate($this->workspaceId($request), array_merge($automation->only(['trigger_type', 'trigger_config']), $validated, compact('nodes', 'edges')), true);
        if (array_key_exists('trigger_type', $validated)) {
            $automation->trigger_type = $validated['trigger_type']; // in-memory only, never persisted
        }

        $context = [];
        $context['_sample_answer'] = $validated['sample_answer'] ?? '[sample reply]';
        if (! empty($validated['sample_message'])) {
            $context['message_body'] = $validated['sample_message'];
        }

        return response()->json(app(AutomationEngine::class)->testRun($automation, $nodes, $edges, $context));
    }

    /**
     * Run a failed automation again from the step that failed.
     *
     * The engine refuses to repeat a claimed step, so a naive reset would
     * fail straight back. When the policy says the step definitely did not
     * deliver, its claim is released and the run resumes there. When it
     * might have delivered, the operator must confirm they checked the chat.
     */
    public function retryRun(Request $request, Automation $automation, AutomationRun $run): RedirectResponse
    {
        $this->authorise($request, $automation);
        abort_unless((int) $run->automation_id === (int) $automation->id, 404);
        $confirmed = $request->boolean('confirmed');

        $run->setRelation('automation', $automation);
        $assessment = app(AutomationRetryPolicy::class)->assess($run);
        if (! $assessment['retryable']) {
            return back()->with('error', $assessment['reason']);
        }
        if ($assessment['needs_confirmation'] && ! $confirmed) {
            return back()->with('error', $assessment['reason']);
        }

        DB::transaction(function () use ($run): void {
            DB::table('automation_step_claims')->where('run_id', $run->id)->where('node_id', $run->current_node_id)->delete();
            // 'pending' is how every new run starts, and the only waiting
            // state the column allows; the job picks it up the same way.
            $run->update([
                'status' => 'pending',
                'resume_node_id' => $run->current_node_id,
                'error' => null,
                'completed_at' => null,
                'wake_at' => null,
            ]);
        });
        ExecuteAutomationRunJob::dispatch($run->id)->afterCommit();

        return back()->with('success', 'Run queued to retry from the step that failed.');
    }

    /**
     * Build an automation from a plain-language description.
     *
     * From the Automations page this creates a paused draft, never an active
     * one: the AI cannot know which templates are approved, so a person always
     * reviews it in the builder first. From inside the builder (persist=false)
     * nothing is saved — the graph is returned to be placed on the canvas and
     * saved only if the person chooses to.
     */
    public function generate(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'persist' => ['nullable', 'boolean'],
        ]);

        try {
            $graph = app(WorkflowGenerator::class)->generate($wid, $validated['prompt']);
        } catch (AiCreditsExhaustedException $e) {
            // Its own status, so the page can say "out of credits" plainly
            // rather than implying the request itself was the problem.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 402);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        // With a single WhatsApp number there is only one sensible choice, so
        // make it; with several, leave it for the person reviewing the draft.
        $accounts = ChannelAccount::where('workspace_id', $wid)->where('channel', 'whatsapp')->where('status', 'active')->pluck('id');
        $triggerConfig = $graph['trigger_config'];
        if ($accounts->count() === 1) {
            $triggerConfig['channel_account_id'] = (int) $accounts->first();
        }

        if (! $request->boolean('persist', true)) {
            return response()->json(['ok' => true, 'graph' => array_merge($graph, ['trigger_config' => $triggerConfig])]);
        }

        $automation = Automation::create([
            'workspace_id' => $wid,
            'name' => $graph['name'],
            'status' => 'draft',
            'trigger_type' => $graph['trigger_type'],
            'trigger_config' => $triggerConfig,
            'nodes' => $graph['nodes'],
            'edges' => $graph['edges'],
        ]);

        return response()->json(['ok' => true, 'redirect' => route('client.automations.edit', $automation->uuid)]);
    }

    private function authorise(Request $request, Automation $automation): void
    {
        abort_unless((int) $automation->workspace_id === $this->workspaceId($request), 403);
    }

    /** @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function normaliseNodes(array $nodes, ?string $triggerType): array
    {
        return array_map(function (array $node) use ($triggerType): array {
            if (! $this->isTriggerNode($node)) {
                return $node;
            }

            $node['type'] = 'trigger';
            $node['data'] = array_merge($node['data'] ?? [], ['triggerType' => $triggerType]);

            return $node;
        }, $nodes);
    }

    /** @param array<string, mixed> $node */
    private function isTriggerNode(array $node): bool
    {
        return in_array($node['type'] ?? '', ['trigger', 'triggerNode'], true)
            || array_key_exists('triggerType', $node['data'] ?? []);
    }
}
