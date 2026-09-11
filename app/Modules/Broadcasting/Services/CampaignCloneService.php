<?php

namespace App\Modules\Broadcasting\Services;

use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CampaignCloneService
{
    public function duplicate(Campaign $source, int $createdBy): Campaign
    {
        $source->loadMissing('steps');
        $copiedCsv = $this->copyCsvAudience($source);

        try {
            return DB::transaction(function () use ($source, $createdBy, $copiedCsv): Campaign {
                $campaign = Campaign::create([
                    'workspace_id' => $source->workspace_id,
                    'name' => Str::limit('Copy of '.$source->name, 128, ''),
                    'channel' => $source->channel,
                    'whatsapp_waba_id' => $source->whatsapp_waba_id,
                    'whatsapp_phone_number_id' => $source->whatsapp_phone_number_id,
                    'sms_provider' => $source->sms_provider,
                    'audience_type' => $source->audience_type,
                    'audience_ref' => $copiedCsv ?? $source->audience_ref,
                    'template_ref' => $source->template_ref,
                    'payload_json' => $source->payload_json,
                    'schedule_at' => null,
                    'timezone' => $source->timezone,
                    'status' => 'draft',
                    'totals_json' => null,
                    'created_by' => $createdBy,
                    'estimated_recipients' => $source->audience_type === 'csv'
                        ? $source->estimated_recipients
                        : 0,
                ]);

                foreach ($source->steps as $step) {
                    $campaign->steps()->create([
                        'position' => $step->position,
                        'name' => $step->name,
                        'recipient_limit' => $step->recipient_limit,
                        'delay_after_previous_seconds' => $step->delay_after_previous_seconds,
                        'rate_per_second' => $step->rate_per_second,
                        'status' => 'pending',
                    ]);
                }

                return $campaign->load('steps');
            });
        } catch (Throwable $exception) {
            if ($copiedCsv) {
                Storage::disk('local')->delete($copiedCsv);
            }

            throw $exception;
        }
    }

    private function copyCsvAudience(Campaign $source): ?string
    {
        if ($source->audience_type !== 'csv') {
            return null;
        }

        $sourcePath = (string) $source->audience_ref;
        $directory = 'campaign-imports/'.$source->workspace_id;
        if (! str_starts_with($sourcePath, $directory.'/') || ! Storage::disk('local')->exists($sourcePath)) {
            throw new RuntimeException('The campaign CSV is missing and cannot be cloned. Upload a new CSV to a draft campaign instead.');
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'csv';
        $destination = $directory.'/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->copy($sourcePath, $destination)) {
            throw new RuntimeException('The campaign CSV could not be copied.');
        }

        return $destination;
    }
}
