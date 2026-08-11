<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\MediaService;
use App\Services\UploadLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $validated = $request->validate([
            // Allow-list of safe media types only. HTML/SVG/scripts are excluded
            // to prevent stored-XSS via files served from the app origin.
            'file' => [
                'required', 'file', 'max:'.$this->uploadLimitService->mediaMaxKilobytes(),
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,mp3,wav,ogg,m4a,mp4,webm,mov',
            ],
            'collection' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $usedBytes = $this->mediaService->usedBytes($user);
        $quotaBytes = $this->mediaService->quotaBytes($user);

        if ($usedBytes + $validated['file']->getSize() > $quotaBytes) {
            return response()->json(['error' => __('Storage quota exceeded.')], 422);
        }

        try {
            $media = $this->mediaService->store($validated['file'], $user, $validated['collection'] ?? 'default');
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
        ], 201);
    }

    public function destroy(Request $request, Media $medium): JsonResponse
    {
        abort_unless($medium->mediable_type === get_class($request->user()) && $medium->mediable_id === $request->user()->id, 403);

        $medium->delete();

        return response()->json(['ok' => true]);
    }
}
