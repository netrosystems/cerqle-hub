<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Services\CampaignAudienceService;
use App\Modules\Broadcasting\Services\CampaignStepService;
use App\Modules\Broadcasting\Services\SmsCampaignCapacityService;
use App\Modules\Shared\Services\ContactService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareSmsCampaignAudienceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 60, 180];

    public function __construct(public readonly int $campaignId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sms-prepare:'.$this->campaignId))->releaseAfter(2)->expireAfter(600)];
    }

    public function handle(
        CampaignAudienceService $audience,
        CampaignStepService $steps,
        SmsCampaignCapacityService $capacity,
    ): void {
        $campaign = Campaign::with('steps')->find($this->campaignId);
        if (! $campaign || $campaign->status !== 'preparing') {
            return;
        }

        $chunkSize = max(100, (int) config('broadcasting.sms.audience_chunk_size', 2000));
        $contactIds = $campaign->audience_type === 'csv'
            ? $this->readCsvChunk($campaign, $chunkSize)
            : $audience->nextIds(
                $campaign,
                (int) $campaign->preparation_cursor,
                (int) $campaign->audience_cutoff_id,
                $chunkSize,
            );

        if ($contactIds === []) {
            $this->finishPreparation($campaign, $capacity);

            return;
        }

        DB::transaction(function () use ($campaign, $contactIds, $steps) {
            /** @var Campaign $locked */
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'preparing') {
                return;
            }

            $now = now();
            $baseOrdinal = (int) $locked->prepared_recipients;
            $uniqueContactIds = array_values(array_unique($contactIds));
            $existingContactIds = CampaignRecipient::where('campaign_id', $campaign->id)
                ->whereIn('contact_id', $uniqueContactIds)
                ->pluck('contact_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $newContactIds = array_values(array_diff($uniqueContactIds, $existingContactIds));
            $rows = [];
            foreach ($newContactIds as $index => $contactId) {
                $step = $steps->forOrdinal($campaign, $baseOrdinal + $index + 1);
                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'campaign_step_id' => $step->id,
                    'contact_id' => $contactId,
                    'status' => 'queued',
                    'attempts' => 0,
                    'idempotency_key' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $inserted = $rows === [] ? 0 : CampaignRecipient::insertOrIgnore($rows);
            $patch = [
                'prepared_recipients' => (int) $locked->prepared_recipients + $inserted,
                'updated_at' => $now,
            ];
            if ($campaign->audience_type !== 'csv') {
                $patch['preparation_cursor'] = max($contactIds);
            }
            $locked->update($patch);
        }, 5);

        self::dispatch($campaign->id)->onQueue('broadcast');
    }

    /** @return array<int, int> */
    private function readCsvChunk(Campaign $campaign, int $limit): array
    {
        $path = $campaign->audience_ref;
        if (
            ! $path || str_contains($path, '..') || str_starts_with($path, '/')
            || str_starts_with($path, '\\') || ! Storage::exists($path)
        ) {
            throw new \RuntimeException('Campaign CSV is missing or has an invalid storage path.');
        }

        $handle = fopen(Storage::path($path), 'r');
        if (! $handle) {
            throw new \RuntimeException('Campaign CSV could not be opened.');
        }

        $ids = [];
        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                return [];
            }
            $header = array_map(fn ($value) => trim(strtolower((string) $value)), $header);

            if ($campaign->preparation_offset > 0) {
                fseek($handle, (int) $campaign->preparation_offset);
            }

            $service = app(ContactService::class);
            while (count($ids) < $limit && ($line = fgetcsv($handle)) !== false) {
                $row = array_combine($header, array_pad($line, count($header), null));
                if (! is_array($row)) {
                    continue;
                }
                try {
                    $contact = $service->upsert($campaign->workspace_id, [
                        'phone_e164' => $row['phone_e164'] ?? $row['phone'] ?? null,
                        'email' => $row['email'] ?? null,
                        'first_name' => $row['first_name'] ?? null,
                        'last_name' => $row['last_name'] ?? null,
                        'country' => $row['country'] ?? null,
                        'language' => $row['language'] ?? null,
                        'opt_in_sms' => $service->coerceOptIn($row['opt_in_sms'] ?? null),
                        'source' => 'campaign_csv',
                    ], false);
                    if ($contact->opt_in_sms && filled($contact->phone_e164)) {
                        $ids[] = $contact->id;
                    }
                } catch (\Throwable $e) {
                    Log::channel('json')->info('campaign.csv.row_failed', [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $campaign->update(['preparation_offset' => ftell($handle)]);
        } finally {
            fclose($handle);
        }

        return $ids;
    }

    private function finishPreparation(Campaign $campaign, SmsCampaignCapacityService $capacity): void
    {
        $campaign->refresh();
        if ($campaign->prepared_recipients === 0) {
            $campaign->update([
                'status' => 'failed',
                'pause_reason' => 'No eligible SMS contacts matched the audience.',
                'totals_json' => ['total' => 0, 'failed_reason' => 'No matching contacts for audience.'],
            ]);
            $capacity->release($campaign);

            return;
        }

        $first = $campaign->steps()->orderBy('position')->first();
        $first?->update(['status' => 'active', 'started_at' => now()]);
        $campaign->update([
            'status' => 'sending',
            'estimated_recipients' => $campaign->prepared_recipients,
            'audience_prepared_at' => now(),
            'totals_json' => [
                'total' => $campaign->prepared_recipients,
                'queued' => $campaign->prepared_recipients,
                'retrying' => 0,
                'sent' => 0,
                'delivered' => 0,
                'read' => 0,
                'failed' => 0,
            ],
        ]);

        PumpSmsCampaignJob::dispatch($campaign->id)->onQueue('broadcast');
    }

    public function failed(\Throwable $exception): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }
        $campaign->update([
            'status' => 'safety_paused',
            'pause_reason' => 'Audience preparation stopped after repeated errors: '.substr($exception->getMessage(), 0, 350),
        ]);
        app(SmsCampaignCapacityService::class)->release($campaign->fresh());
    }
}
