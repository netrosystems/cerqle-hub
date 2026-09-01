<?php

namespace App\Modules\Social\Services;

use App\Models\Media;
use App\Models\User;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialMediaLifecycleService
{
    /** @param array<int, int|string> $mediaIds */
    public function syncPostMedia(SocialPost $post, User $user, array $mediaIds): void
    {
        $ids = collect($mediaIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $mediaQuery = Media::query()
            ->whereIn('id', $ids)
            ->where('is_temporary', true)
            ->where('mediable_type', $user::class);

        if ($user->client_id) {
            $mediaQuery->whereIn('mediable_id', $user->client()->firstOrFail()->users()->select('id'));
        } else {
            $mediaQuery->where('mediable_id', $user->getKey());
        }

        $media = $mediaQuery->get();

        if ($media->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'media_ids' => ['One or more uploaded media files are invalid or do not belong to you.'],
            ]);
        }

        DB::transaction(function () use ($post, $ids): void {
            $previous = $post->media()->pluck('media.id');
            $post->media()->sync($ids->all());

            Media::whereIn('id', $ids)->update([
                'quota_released_at' => null,
                'purge_after' => null,
            ]);

            $removed = $previous->diff($ids);
            Media::whereIn('id', $removed)->get()->each(fn (Media $media) => $this->releaseIfUnused($media));
        });
    }

    public function releaseAfterSuccessfulPublish(SocialPost $post): void
    {
        $postMedia = $post->media()->get();
        $postMedia->each(function (Media $media) use ($post): void {
            $stillRequired = $media->socialPosts()
                ->where('social_media_posts.id', '!=', $post->getKey())
                ->whereNotIn('status', ['published'])
                ->exists();

            if (! $stillRequired) {
                $media->update([
                    'quota_released_at' => now(),
                    'purge_after' => now()->addDay(),
                ]);
            }
        });

        if ($postMedia->isNotEmpty() && $postMedia->every(fn (Media $media) => $media->fresh()->quota_released_at !== null)) {
            $post->update(['temporary_media_released_at' => now()]);
        }
    }

    public function detachDeletedPost(SocialPost $post): void
    {
        $media = $post->media()->get();
        $post->media()->detach();

        $media->each(function (Media $item): void {
            $this->releaseIfUnused($item);
        });
    }

    private function releaseIfUnused(Media $media): void
    {
        if ($media->socialPosts()->whereNotIn('status', ['published'])->exists()) {
            return;
        }

        $media->update([
            'quota_released_at' => now(),
            'purge_after' => now()->addDay(),
        ]);
        SocialPost::query()
            ->whereIn('id', $media->socialPosts()->where('status', 'published')->pluck('social_media_posts.id'))
            ->update(['temporary_media_released_at' => now()]);
    }
}
