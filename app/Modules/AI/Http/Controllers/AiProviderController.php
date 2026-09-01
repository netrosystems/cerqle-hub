<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\ProviderErrorPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderController extends Controller
{
    private const PROVIDERS = ['openai', 'anthropic', 'gemini', 'deepseek'];

    public function __construct(private readonly AiCreditService $credits) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)->get()->keyBy('provider');

        $providers = self::PROVIDERS;
        $list = collect($providers)->map(fn ($p) => [
            'provider' => $p,
            'enabled' => $configs->get($p)?->enabled ?? false,
            'configured' => ! empty($configs->get($p)?->credentials),
            'default_model_chat' => $configs->get($p)?->default_model_chat ?? '',
            'default_model_embed' => $configs->get($p)?->default_model_embed ?? '',
            'test_status' => $configs->get($p)?->test_status,
            'tested_at' => $configs->get($p)?->tested_at?->toIso8601String(),
        ]);

        return Inertia::render('AI/Providers/Index', [
            'providers' => $list,
            'activeProvider' => $list->firstWhere('enabled', true)['provider'] ?? null,
            'providerMode' => $this->credits->mode((int) $workspaceId),
            'aiCredits' => $this->credits->usageForWorkspace((int) $workspaceId),
        ]);
    }

    public function updateMode(Request $request): RedirectResponse
    {
        $validated = $request->validate(['mode' => ['required', 'in:managed,byok,auto_fallback']]);
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        if (in_array($validated['mode'], ['byok', 'auto_fallback'], true)
            && ! LlmManager::activeByokProvider($workspaceId)) {
            throw ValidationException::withMessages(['mode' => 'Test and enable your API provider before selecting this mode.']);
        }
        $this->credits->setMode($workspaceId, $validated['mode']);

        return back()->with('success', 'AI provider mode updated.');
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'default_model_chat' => ['nullable', 'string', 'max:64'],
            'default_model_embed' => ['nullable', 'string', 'max:64'],
            'enabled' => ['boolean'],
        ]);

        $enabled = (bool) ($validated['enabled'] ?? false);

        DB::transaction(function () use ($workspaceId, $provider, $validated, $enabled): void {
            // Lock the workspace's provider settings as one unit. This makes
            // choosing a new provider atomic even if two teammates save from
            // different browser sessions at the same time.
            AiProviderConfig::where('workspace_id', $workspaceId)->lockForUpdate()->get();
            $config = AiProviderConfig::firstOrNew(['workspace_id' => $workspaceId, 'provider' => $provider]);
            $creds = $config->credentials ?? [];

            if (! empty($validated['api_key']) && ! preg_match('/^•+/', $validated['api_key'])) {
                $creds['api_key'] = $validated['api_key'];
                $config->test_status = null;
                $config->tested_at = null;
            }

            if ($enabled && empty($creds['api_key'])) {
                throw ValidationException::withMessages([
                    'api_key' => 'An API key is required before this provider can be enabled.',
                ]);
            }

            if ($enabled) {
                AiProviderConfig::where('workspace_id', $workspaceId)
                    ->where('provider', '!=', $provider)
                    ->where('enabled', true)
                    ->update(['enabled' => false, 'updated_at' => now()]);
            }

            $config->fill([
                'credentials' => $creds,
                'default_model_chat' => $validated['default_model_chat'] ?? $config->default_model_chat,
                'default_model_embed' => $validated['default_model_embed'] ?? $config->default_model_embed,
                'enabled' => $enabled,
            ])->save();
        });

        return back()->with('success', $enabled
            ? ucfirst($provider).' is now the active AI provider. Other providers were disabled.'
            : ucfirst($provider).' configuration saved.');
    }

    public function test(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $config = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->first();

        if (! $config || empty($config->credentials['api_key'] ?? '')) {
            return response()->json([
                'ok' => false,
                'error' => 'Save an API key before testing this provider.',
                'error_code' => 'provider_not_configured',
            ], 422);
        }

        try {
            $client = LlmManager::build($provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]);

            $client->chat([
                ['role' => 'user', 'content' => 'Reply with OK.'],
            ], [
                'max_tokens' => 8,
                'temperature' => 0,
            ]);

            $supportsEmbeddings = in_array($provider, ['openai', 'gemini'], true);
            if ($supportsEmbeddings) {
                $client->embed(['connection test']);
            }
            $config->update(['test_status' => 'passed', 'tested_at' => now()]);

            return response()->json([
                'ok' => true,
                'message' => ucfirst($provider).' connected successfully.',
                'capabilities' => [
                    'chat' => true,
                    'embeddings' => $supportsEmbeddings,
                ],
            ]);
        } catch (\Throwable $e) {
            $config->update(['test_status' => 'failed', 'tested_at' => now()]);
            Log::warning('ai.provider_test_failed', [
                'workspace_id' => $workspaceId,
                'provider' => $provider,
                'exception' => $e,
            ]);

            $error = ProviderErrorPresenter::present($e);

            return response()->json([
                'ok' => false,
                'error' => $error['message'],
                'error_code' => $error['code'],
            ], 422);
        }
    }
}
