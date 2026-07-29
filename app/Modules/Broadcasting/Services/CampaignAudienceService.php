<?php

namespace App\Modules\Broadcasting\Services;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\SegmentResolver;
use Illuminate\Database\Eloquent\Builder;

class CampaignAudienceService
{
    public function __construct(private readonly SegmentResolver $segments) {}

    public function query(Campaign $campaign): Builder
    {
        $query = match ($campaign->audience_type) {
            'segment' => $this->segmentQuery($campaign),
            'tag' => Contact::where('workspace_id', $campaign->workspace_id)
                ->whereHas('tags', fn ($q) => $q->where('contact_tags.id', $campaign->audience_ref)),
            'contact_list' => Contact::where('workspace_id', $campaign->workspace_id),
            default => Contact::whereRaw('1 = 0'),
        };

        $optInColumn = match ($campaign->channel) {
            'whatsapp' => 'opt_in_whatsapp',
            'sms' => 'opt_in_sms',
            'email' => 'opt_in_email',
            default => null,
        };

        if ($optInColumn) {
            $query->where($optInColumn, true);
        }

        if ($campaign->channel === 'email') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } else {
            $query->whereNotNull('phone_e164')->where('phone_e164', '!=', '');
        }

        return $query->select('contacts.id')->distinct();
    }

    public function count(Campaign $campaign, ?int $cutoffId = null): int
    {
        return (int) $this->query($campaign)
            ->when($cutoffId, fn ($q) => $q->where('contacts.id', '<=', $cutoffId))
            ->count('contacts.id');
    }

    public function maxContactId(Campaign $campaign): ?int
    {
        return $this->query($campaign)->max('contacts.id');
    }

    /** @return array<int, int> */
    public function nextIds(Campaign $campaign, int $afterId, int $cutoffId, int $limit): array
    {
        return $this->query($campaign)
            ->where('contacts.id', '>', $afterId)
            ->where('contacts.id', '<=', $cutoffId)
            ->orderBy('contacts.id')
            ->limit($limit)
            ->pluck('contacts.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function segmentQuery(Campaign $campaign): Builder
    {
        $segment = Segment::where('workspace_id', $campaign->workspace_id)
            ->find($campaign->audience_ref);

        return $segment
            ? $this->segments->query($segment)
            : Contact::whereRaw('1 = 0');
    }
}
