<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Whatsapp\Models\WhatsappWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Organization-wide inventory limits; inactive resources continue to occupy a slot. */
class ChannelPlanLimitService
{
    public const CHANNELS = ['whatsapp', 'messenger', 'instagram'];

    public const LABELS = [
        'messaging_channels' => 'Messaging channels',
        'website_widgets' => 'Website widgets',
        'whatsapp_chatbots' => 'WA Chatbots',
        'social_accounts' => 'Social accounts',
        'messaging_messages_per_month' => 'Messages this month',
    ];

    public function account(Workspace $workspace, bool $lock = false): array
    {
        if ($workspace->client_id) {
            $owner = Client::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($workspace->client_id);
            $plan = $owner->effectivePlan();
            $ids = Workspace::where('client_id', $owner->id)->pluck('id');
        } else {
            $owner = User::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($workspace->owner_id);
            $plan = $owner->effectiveSubscription()?->plan;
            $ids = Workspace::whereNull('client_id')->where('owner_id', $owner->id)->pluck('id');
        }

        return [$plan, $ids];
    }

    public function usage(Workspace $workspace, string $key, bool $lock = false): array
    {
        [$plan, $ids] = $this->account($workspace, $lock);
        $value = $plan?->limitValue($key);
        $limit = ! $plan ? 0 : ($value === null ? null : max(0, (int) $value));
        $query = match ($key) {
            'messaging_channels' => ChannelAccount::whereIn('channel', self::CHANNELS),
            'website_widgets' => ChatWidget::query(),
            'whatsapp_chatbots' => WhatsappWidget::query(),
            'social_accounts' => SocialAccount::query(),
        };

        return $this->summary($key, (int) $query->whereIn('workspace_id', $ids)->count(), $limit);
    }

    public function summary(string $key, int $used, ?int $limit): array
    {
        return [
            'key' => $key, 'label' => __(self::LABELS[$key]), 'used' => $used, 'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'unlimited' => $limit === null, 'is_full' => $limit !== null && $used >= $limit,
        ];
    }

    public function ensureCapacity(array $usage): void
    {
        if ($usage['is_full']) {
            throw ValidationException::withMessages([
                'plan_limit' => __(':label limit reached (:used/:limit across all workspaces). Remove an unused item or upgrade your plan.', [
                    'label' => $usage['label'], 'used' => $usage['used'], 'limit' => $usage['limit'],
                ]),
            ]);
        }
    }

    public function save(Model $model, string $key, callable $save): bool
    {
        // Token refreshes, edits, disabling and reauthorization must work at/over capacity.
        if ($model->exists && ! $model->isDirty('workspace_id') && ! $model->isDirty('channel')) {
            return $save();
        }
        $workspace = Workspace::findOrFail($model->workspace_id);

        return DB::transaction(function () use ($workspace, $model, $key, $save) {
            // First transactional read locks the billing owner, also on MySQL REPEATABLE READ.
            $usage = $this->usage($workspace, $key, true);
            if (! $model->exists) {
                $existing = $this->existingIdentity($model);
                if ($existing) {
                    $model->setAttribute($model->getKeyName(), $existing->getKey());
                    $model->exists = true;

                    return $save();
                }
            } else {
                [, $ids] = $this->account($workspace);
                if ($ids->contains($model->getOriginal('workspace_id')) && ! $model->isDirty('channel')) {
                    return $save();
                }
            }
            $this->ensureCapacity($usage);

            return $save();
        }, 3);
    }

    private function existingIdentity(Model $model): ?Model
    {
        $query = $model->newQuery()->where('workspace_id', $model->workspace_id);
        if ($model instanceof SocialAccount) {
            return $query->where('network', $model->network)->where('account_id', $model->account_id)->first();
        }
        if ($model instanceof ChannelAccount) {
            $query->where('channel', $model->channel);
            if ($model->channel === 'whatsapp' && $model->phone_number_id) {
                return $query->where('phone_number_id', $model->phone_number_id)->first();
            }
            $field = $model->channel === 'messenger' ? 'page_id' : 'instagram_page_id';
            if ($id = ($model->meta_json[$field] ?? null)) {
                return $query->where("meta_json->{$field}", $id)->first();
            }
        }

        return null;
    }
}
