<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiCreditAdjustment;
use App\Modules\AI\Models\AiCreditPeriod;
use App\Modules\AI\Models\AiCreditUsage;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AiDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Provider distribution across all workspaces
        $providerStats = AiProviderConfig::where('enabled', true)
            ->select('provider', DB::raw('count(*) as count'))
            ->groupBy('provider')
            ->pluck('count', 'provider')
            ->toArray();

        // Total workspaces that have at least one enabled AI provider
        $configuredWorkspaces = AiProviderConfig::where('enabled', true)
            ->distinct('workspace_id')
            ->count('workspace_id');

        // Qdrant status
        $qdrantCredentials = CredentialResolver::system()->qdrant()?->toArray();
        $qdrantUrl = $qdrantCredentials['url'] ?? null;
        $qdrantConfigured = filled($qdrantUrl);
        $qdrantHealthy = false;
        if ($qdrantConfigured) {
            try {
                $request = Http::timeout(3);
                if (filled($qdrantCredentials['api_key'] ?? null)) {
                    $request = $request->withHeaders(['api-key' => $qdrantCredentials['api_key']]);
                }
                $resp = $request->get(rtrim((string) $qdrantUrl, '/').'/healthz');
                $qdrantHealthy = $resp->successful();
            } catch (\Throwable) {
                $qdrantHealthy = false;
            }
        }

        // Usage stats (last 30 days)
        $usageStats = AiRun::where('created_at', '>=', now()->subDays(30))
            ->select(
                DB::raw('SUM(prompt_tokens + completion_tokens) as total_tokens'),
                DB::raw('COUNT(*) as total_runs'),
                DB::raw('SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END) as error_runs'),
                DB::raw('AVG(latency_ms) as avg_latency_ms')
            )
            ->first();

        // Top models used
        $topModels = AiRun::where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('model')
            ->select('model', DB::raw('count(*) as runs'), DB::raw('sum(prompt_tokens + completion_tokens) as tokens'))
            ->groupBy('model')
            ->orderByDesc('runs')
            ->limit(5)
            ->get();

        // Daily token usage (last 14 days)
        $dailyUsage = AiRun::where('created_at', '>=', now()->subDays(14))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(prompt_tokens + completion_tokens) as tokens'),
                DB::raw('COUNT(*) as runs')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // KB and document counts
        $kbCount = AiKnowledgeBase::count();
        $documentStats = AiKbDocument::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $chatbotCount = AiChatbot::count();
        $activeChatbotCount = AiChatbot::where('enabled', true)->count();

        $creditStats = AiCreditUsage::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('COALESCE(SUM(charged_credits), 0) as consumed')
            ->selectRaw('COALESCE(SUM(cost_microusd), 0) as cost_microusd')
            ->selectRaw("SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunds")
            ->selectRaw("SUM(CASE WHEN provider_source = 'byok' THEN 1 ELSE 0 END) as byok_actions")
            ->first();
        $creditsByFeature = AiCreditUsage::where('created_at', '>=', now()->subDays(30))
            ->select('feature_key', DB::raw('SUM(charged_credits) as credits'), DB::raw('COUNT(*) as actions'))
            ->groupBy('feature_key')->orderByDesc('credits')->get();

        return Inertia::render('Admin/AI/Dashboard', [
            'providerStats' => $providerStats,
            'configuredWorkspaces' => $configuredWorkspaces,
            'qdrant' => [
                'configured' => $qdrantConfigured,
                'healthy' => $qdrantHealthy,
                'url' => $qdrantConfigured ? $qdrantUrl : null,
            ],
            'usage' => [
                'total_tokens' => (int) ($usageStats->total_tokens ?? 0),
                'total_runs' => (int) ($usageStats->total_runs ?? 0),
                'error_runs' => (int) ($usageStats->error_runs ?? 0),
                'avg_latency_ms' => (int) ($usageStats->avg_latency_ms ?? 0),
            ],
            'topModels' => $topModels,
            'dailyUsage' => $dailyUsage,
            'kbCount' => $kbCount,
            'documentStats' => $documentStats,
            'chatbotCount' => $chatbotCount,
            'activeChatbotCount' => $activeChatbotCount,
            'creditStats' => [
                'consumed' => (int) $creditStats->consumed,
                'cost_microusd' => (int) $creditStats->cost_microusd,
                'refunds' => (int) $creditStats->refunds,
                'byok_actions' => (int) $creditStats->byok_actions,
            ],
            'creditsByFeature' => $creditsByFeature,
            'creditPeriods' => AiCreditPeriod::where('period_end', '>', now())->latest('used_credits')->limit(20)->get(),
            'adjustments' => AiCreditAdjustment::latest()->limit(20)->get(),
        ]);
    }

    public function adjustCredits(Request $request, AiCreditPeriod $period, AiCreditService $credits): RedirectResponse
    {
        $validated = $request->validate([
            'credits' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $credits->adjust($period, (int) $validated['credits'], $validated['reason'], auth('admin')->id());

        return back()->with('success', 'AI credits adjusted and audit event recorded.');
    }
}
