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

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
        ]);

        AiChatbot::create(array_merge($validated, [
            'workspace_id' => $wid,
            'answer_scope' => 'business_only',
            'fallback_mode' => 'clarify_then_handoff',
        ]));

        return back()->with('success', 'Smart Bot created.');
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
        ]);
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
