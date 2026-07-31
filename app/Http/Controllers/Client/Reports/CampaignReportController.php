<?php

namespace App\Http\Controllers\Client\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignReportController extends Controller
{
    public function __invoke(Request $request, Campaign $campaign, AnalyticsService $analytics): Response
    {
        // Match the client-app workspace selection used elsewhere
        // (current_workspace_id wins over the user's home workspace_id).
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_if((int) $campaign->workspace_id !== (int) $workspaceId, 403);

        // Make sure the totals on the campaign row reflect the latest recipient data.
        $campaign->updateTotals();

        $total = CampaignRecipient::where('campaign_id', $campaign->id)->count();
        $isSms = $campaign->channel === 'sms';
        $sent = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();
        $delivered = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['delivered', 'read'])
            ->count();
        $read = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'read')
            ->count();
        if ($isSms) {
            // Successful gateway submissions are the delivery signal available
            // for SMS. SMS has no meaningful recipient read status.
            $delivered = $sent;
            $sent = 0;
            $read = 0;
        }
        $failed = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'failed')
            ->count();
        $clicked = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereNotNull('clicked_at')
            ->count();
        $optedOut = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereNotNull('opted_out_at')
            ->count();

        $kpis = [
            'total' => $total,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,
            'clicked' => $clicked,
            'opted_out' => $optedOut,
            'delivered_pct' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
            'read_pct' => $total > 0 ? round(($read / $total) * 100, 1) : 0,
            'failed_pct' => $total > 0 ? round(($failed / $total) * 100, 1) : 0,
            'clicked_pct' => $total > 0 ? round(($clicked / $total) * 100, 1) : 0,
        ];

        $statusFilter = (string) $request->input('status', '');
        $recipientsQuery = CampaignRecipient::where('campaign_id', $campaign->id)
            ->with(['contact:id,first_name,last_name,phone_e164,email'])
            ->when($statusFilter !== '', function ($query) use ($isSms, $statusFilter) {
                if ($isSms && $statusFilter === 'delivered') {
                    $query->whereIn('status', ['sent', 'delivered', 'read']);

                    return;
                }

                $query->where('status', $statusFilter);
            })
            ->orderByDesc('updated_at');

        $recipients = $recipientsQuery->paginate(50)->withQueryString();

        // Average lag (in seconds) between status transitions.
        $lag = $analytics->campaignDeliveryLag($campaign->id);

        return Inertia::render('client/Reports/Campaign/Show', [
            'campaign' => $campaign->only('id', 'uuid', 'name', 'channel', 'status', 'created_at'),
            'kpis' => $kpis,
            'funnel' => $analytics->campaignFunnel($campaign->id),
            'deliveryOverTime' => $this->normaliseSmsDeliverySeries(
                $analytics->campaignDeliveryOverTime($campaign->id),
                $isSms,
            ),
            'failedReasons' => $analytics->campaignFailedReasons($campaign->id),
            'lag' => $lag,
            'recipients' => $recipients,
            'filters' => $request->only('status'),
        ]);
    }

    /** @param array<int, array{hour: string, sent: int, delivered: int, read: int}> $series */
    private function normaliseSmsDeliverySeries(array $series, bool $isSms): array
    {
        if (! $isSms) {
            return $series;
        }

        return array_map(fn (array $point) => array_merge($point, [
            'sent' => 0,
            'delivered' => (int) ($point['sent'] ?? 0) + (int) ($point['delivered'] ?? 0) + (int) ($point['read'] ?? 0),
            'read' => 0,
        ]), $series);
    }
}
