<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\ProviderErrorPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiChatbotController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $chatbots = AiChatbot::where('workspace_id', $wid)->with('knowledgeBase')->latest()->get();
        $knowledgeBases = AiKnowledgeBase::where('workspace_id', $wid)
            ->get(['id', 'name', 'business_name', 'business_purpose', 'target_audience']);

        return Inertia::render('AI/Chatbots/Index', [
            'chatbots' => $chatbots,
            'knowledgeBases' => $knowledgeBases,
        ]);
    }

    /**
     * The bot's own page: identity, what it knows, and the playground.
     *
     * Knowledge used to be a separate destination a client had to find and
     * populate before the bot could answer. It is the bot's knowledge, so it
     * lives with the bot.
     */
    public function show(Request $request, AiChatbot $chatbot): Response
    {
        $this->authorise($request, $chatbot);
        $chatbot->load('knowledgeBase.documents');
        $wid = $this->workspaceId($request);

        // The knowledge step renders the same screen as the knowledge base page,
        // so it needs the same upload limits rather than guessed defaults.
        $kbController = app(AiKnowledgeBaseController::class);
        $uploadMaxKb = $kbController->kbUploadMaxKb();

        return Inertia::render('AI/Bots/Show', [
            'bot' => $chatbot,
            'knowledgeBase' => $chatbot->knowledgeBase,
            'documents' => $chatbot->knowledgeBase?->documents()->latest('id')->get() ?? [],
            'kbUploadMaxKb' => $uploadMaxKb,
            'kbUploadMaxMb' => round($uploadMaxKb / 1024, 1),
            'kbAppUploadMaxMb' => AiKnowledgeBaseController::UPLOAD_MAX_KB / 1024,
            'readiness' => $this->readiness($chatbot, $wid),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('AI/Bots/Create', [
            'knowledgeBases' => AiKnowledgeBase::where('workspace_id', $this->workspaceId($request))
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    /**
     * Creates the bot and the knowledge it answers from in one go.
     *
     * Previously this made an empty bot and left the client to discover that a
     * knowledge base was a separate thing they had to build first and attach
     * afterwards. The two are one job, so they are one request.
     */
    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'system_prompt' => ['nullable', 'string', 'max:4000'],
            'tone' => ['nullable', 'string', 'max:40'],
            'business_name' => ['nullable', 'string', 'max:160'],
            'business_purpose' => ['nullable', 'string', 'max:2000'],
            'target_audience' => ['nullable', 'string', 'max:2000'],
            // Set when the client chose to reuse another bot's knowledge rather
            // than start a new one.
            'ai_kb_id' => ['nullable', 'integer'],
        ]);

        $bot = DB::transaction(function () use ($validated, $wid): AiChatbot {
            $kbId = $validated['ai_kb_id'] ?? null;
            if ($kbId !== null && ! AiKnowledgeBase::where('workspace_id', $wid)->whereKey($kbId)->exists()) {
                $kbId = null;
            }

            if ($kbId === null) {
                $kbId = AiKnowledgeBase::create([
                    'workspace_id' => $wid,
                    'name' => $validated['name'],
                    'business_name' => $validated['business_name'] ?? null,
                    'business_purpose' => $validated['business_purpose'] ?? null,
                    'target_audience' => $validated['target_audience'] ?? null,
                ])->id;
            }

            return AiChatbot::create([
                'workspace_id' => $wid,
                'name' => $validated['name'],
                'system_prompt' => $validated['system_prompt'] ?? null,
                // The column is NOT NULL with its own default; passing an
                // explicit null would override that and fail the insert.
                'tone' => ($validated['tone'] ?? null) ?: 'professional',
                'ai_kb_id' => $kbId,
                'answer_scope' => 'business_only',
                'fallback_mode' => 'clarify_then_handoff',
            ]);
        });

        return redirect()
            ->route('client.ai.chatbots.show', $bot->uuid)
            ->with('success', 'Smart Bot created. Add what it should know below.');
    }

    /**
     * The bot's business details.
     *
     * Its own endpoint rather than the knowledge base's, because here the name
     * and purpose are required — they are what lets the bot answer a general
     * question at all — while renaming a knowledge base elsewhere must stay
     * possible without them.
     */
    public function updateBusinessProfile(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        $kb = $chatbot->knowledgeBase;
        abort_unless($kb !== null, 404);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'business_purpose' => ['required', 'string', 'max:2000'],
            'target_audience' => ['nullable', 'string', 'max:2000'],
        ], [
            'business_name.required' => 'The bot needs to know what your business is called.',
            'business_purpose.required' => 'Say what you do, so the bot can answer general questions about it.',
        ]);

        $kb->update($validated);

        return back()->with('success', 'Business details saved.');
    }

    /**
     * What still stands between this bot and answering a customer.
     *
     * Every one of these has presented identically in production — the bot
     * replies with its fallback line — so naming the specific cause is the
     * whole point.
     *
     * @return list<array{key:string,label:string,ok:bool,detail:string}>
     */
    private function readiness(AiChatbot $chatbot, int $workspaceId): array
    {
        $kb = $chatbot->knowledgeBase;
        $indexed = $kb ? $kb->documents()->where('status', 'indexed')->count() : 0;
        $failed = $kb ? $kb->documents()->where('status', 'error')->count() : 0;
        // Name and purpose are what the bot actually needs; audience is a bonus.
        $profileComplete = $kb && $kb->business_name && $kb->business_purpose;

        return [
            [
                'key' => 'enabled',
                'label' => 'Bot is on',
                'ok' => (bool) $chatbot->enabled,
                'detail' => $chatbot->enabled ? 'Ready to answer.' : 'Switch it on to start answering.',
            ],
            [
                'key' => 'knowledge',
                'label' => 'Knowledge indexed',
                'ok' => $indexed > 0,
                'detail' => match (true) {
                    ! $kb => 'No knowledge attached yet.',
                    $indexed > 0 && $failed > 0 => $indexed.' ready, '.$failed.' could not be read.',
                    $indexed > 0 => $indexed.' '.($indexed === 1 ? 'document' : 'documents').' ready.',
                    $failed > 0 => 'Every source failed to index.',
                    default => 'Add a website, file or text for it to answer from.',
                },
            ],
            [
                'key' => 'profile',
                'label' => 'Business details',
                'ok' => (bool) $profileComplete,
                'detail' => $profileComplete
                    ? 'Used for general questions about your business.'
                    : 'Without it the bot only answers from your documents.',
            ],
        ];
    }

    public function update(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'ai_kb_id' => ['nullable', 'integer'],
            'system_prompt' => ['nullable', 'string', 'max:8192'],
            'tone' => ['nullable', 'string', 'max:64'],
            'answer_scope' => ['sometimes', 'in:business_only,verified_only,general'],
            'max_context_chunks' => ['nullable', 'integer', 'min:1', 'max:20'],
            'fallback_reply' => ['nullable', 'string', 'max:512'],
            'fallback_mode' => ['sometimes', 'in:clarify_then_handoff,handoff'],
            'confidence_threshold' => ['sometimes', 'numeric', 'min:0.1', 'max:1'],
            'clarification_threshold' => ['sometimes', 'numeric', 'min:0', 'max:0.99'],
            'channels' => ['nullable', 'array'],
            'enabled' => ['boolean'],
            // Sent by the "How it answers" step so the tick reflects a real
            // decision rather than the column defaults.
            'behaviour_set' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated['behaviour_set'])) {
            $validated['behaviour_set_at'] = now();
        }
        unset($validated['behaviour_set']);

        // Verify the knowledge base belongs to this workspace
        if (! empty($validated['ai_kb_id'])) {
            $kbExists = AiKnowledgeBase::where('workspace_id', $wid)
                ->where('id', $validated['ai_kb_id'])
                ->exists();
            abort_unless($kbExists, 422);
        }
        $confidence = (float) ($validated['confidence_threshold'] ?? $chatbot->confidence_threshold ?? 0.72);
        $clarification = (float) ($validated['clarification_threshold'] ?? $chatbot->clarification_threshold ?? 0.47);
        if ($clarification >= $confidence) {
            throw ValidationException::withMessages([
                'clarification_threshold' => 'The clarification threshold must be lower than the verified answer threshold.',
            ]);
        }

        $chatbot->update($validated);

        return back()->with('success', 'Smart Bot updated.');
    }

    public function destroy(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        $chatbot->delete();

        return back()->with('success', 'Smart Bot deleted.');
    }

    public function playground(Request $request, AiChatbot $chatbot): JsonResponse
    {
        $this->authorise($request, $chatbot);
        $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array'],
        ]);

        if (! $chatbot->enabled) {
            return response()->json([
                'error' => 'Enable this chatbot before testing it.',
                'error_code' => 'chatbot_disabled',
            ], 422);
        }

        try {
            $result = app(ChatbotRunner::class)->runForApi(
                $chatbot,
                $request->message,
                $this->workspaceId($request),
                $request->input('history', []),
                throwProviderErrors: true,
            );
            $reply = $result['reply'];

            if (blank($reply)) {
                return response()->json([
                    'error' => 'The AI provider returned an empty response. Check the selected model and try again.',
                    'error_code' => 'provider_empty_response',
                ], 422);
            }

            return response()->json([
                'reply' => $reply,
                'answer' => collect($result)->except(['reply', 'tokens_used'])->all(),
            ]);
        } catch (\Throwable $e) {
            $error = ProviderErrorPresenter::present($e);

            return response()->json([
                'error' => $error['message'],
                'error_code' => $error['code'],
            ], $error['code'] === 'ai_credits_exhausted' ? 402 : 422);
        }
    }

    private function authorise(Request $request, AiChatbot $chatbot): void
    {
        abort_unless((int) $chatbot->workspace_id === $this->workspaceId($request), 403);
    }
}
