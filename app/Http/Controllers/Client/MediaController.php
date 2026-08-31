<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Media;
use App\Services\MediaService;
use App\Services\UploadLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MediaController extends Controller
{
    public function __construct(
        private MediaService $mediaService,
        private UploadLimitService $uploadLimitService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $usedBytes = $this->mediaService->usedBytes($user);
        $quotaBytes = $this->mediaService->quotaBytes($user);

        $files = Media::where('mediable_type', get_class($user))
            ->where('mediable_id', $user->id)
            ->latest()
            ->paginate(24)
            ->through(fn ($m) => [
                'id' => $m->id,
                'filename' => $m->filename,
                'mime_type' => $m->mime_type,
                'size_bytes' => $m->size_bytes,
                'url' => $m->url(),
                'collection' => $m->collection,
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return Inertia::render('client/Media/Index', [
            'files' => $files,
            'usedBytes' => $usedBytes,
            'quotaBytes' => $quotaBytes,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $collection = $request->string('collection')->toString();
        $uploadedFile = $request->file('file');
        $isSocialVideo = in_array($collection, ['social', 'social-video'], true)
            && $uploadedFile
            && str_starts_with((string) $uploadedFile->getMimeType(), 'video/');
        $isSocialImage = $collection === 'social'
            && $uploadedFile
            && str_starts_with((string) $uploadedFile->getMimeType(), 'image/');
        $isYoutubeThumbnail = $collection === 'social-thumbnail';
        $maxKilobytes = $isSocialVideo
            ? $this->uploadLimitService->youtubeVideoMaxKilobytes()
            : ($isYoutubeThumbnail
                ? 2048
                : ($isSocialImage
                    ? $this->uploadLimitService->socialImageMaxKilobytes()
                    : $this->uploadLimitService->mediaMaxKilobytes()));
        $allowedExtensions = $collection === 'social-video'
            ? 'mp4,webm,mov'
            : ($isYoutubeThumbnail
                ? 'jpg,jpeg,png'
                : 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,mp3,wav,ogg,m4a,mp4,webm,mov');

        $validated = $request->validate([
            // Allow-list of safe media types only. HTML/SVG/scripts are excluded
            // to prevent stored-XSS via files served from the app origin.
            'file' => [
                'required', 'file', 'max:'.$maxKilobytes,
                'mimes:'.$allowedExtensions,
            ],
            'collection' => ['nullable', 'string', 'max:64'],
        ], [
            'file.max' => $isSocialVideo
                ? __('Social videos cannot be larger than 500 MB.')
                : ($isSocialImage
                    ? __('Social images cannot be larger than 25 MB.')
                    : __('This file is larger than the allowed upload limit.')),
            'file.mimes' => __('This media type is not supported for upload.'),
        ]);

        $user = $request->user();
        try {
            $media = DB::transaction(function () use ($user, $validated) {
                // Serialize quota checks for one user so concurrent uploads
                // cannot both pass against the same remaining capacity.
                if ($user->client_id) {
                    Client::query()->whereKey($user->client_id)->lockForUpdate()->firstOrFail();
                } else {
                    $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();
                }
                $usedBytes = $this->mediaService->usedBytes($user);
                $quotaBytes = $this->mediaService->quotaBytes($user);

                if ($usedBytes + $validated['file']->getSize() > $quotaBytes) {
                    throw ValidationException::withMessages([
                        'file' => [__('Storage quota exceeded. Delete unused media, wait for published-media cleanup, or upgrade your plan.')],
                    ]);
                }

                return $this->mediaService->store($validated['file'], $user, $validated['collection'] ?? 'default');
            }, 3);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Media upload could not be stored.', [
                'user_id' => $user->id,
                'collection' => $validated['collection'] ?? 'default',
                'filename' => $validated['file']->getClientOriginalName(),
                'exception' => $e,
            ]);

            return response()->json([
                'error' => __('The upload could not be saved. Check the configured storage provider and server write permissions.'),
            ], 500);
        }

        return response()->json([
            'id' => $media->id,
            'filename' => $media->filename,
            'url' => $media->url(),
            'size_bytes' => $media->size_bytes,
            'media_id' => $media->id,
            'storage' => $this->mediaService->usage($user),
        ], 201);
    }

    public function destroy(Request $request, Media $medium): JsonResponse
    {
        abort_unless($medium->mediable_type === get_class($request->user()) && $medium->mediable_id === $request->user()->id, 403);

        if ($medium->socialPosts()->whereNotIn('status', ['published'])->exists()) {
            return response()->json([
                'error' => __('This media is still used by a draft, scheduled, publishing, or failed post.'),
            ], 422);
        }

        $medium->delete();

        return response()->json(['ok' => true]);
    }
}
