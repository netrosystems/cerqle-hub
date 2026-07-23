<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\EmbeddingStore;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AiKnowledgeBaseController extends Controller
{
    public function __construct(
        private StorageManager $storage,
        private EmbeddingStore $embeddings,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $kbs = AiKnowledgeBase::where('workspace_id', $workspaceId)
            ->withCount('documents')
            ->latest()->get();

        return Inertia::render('AI/KnowledgeBases/Index', ['knowledgeBases' => $kbs]);
    }

    public function show(Request $request, AiKnowledgeBase $kb): Response
    {
        $this->authorise($request, $kb);
        $kb->load('documents');

        $kbUploadMaxKb = $this->kbUploadMaxKb();

        return Inertia::render('AI/KnowledgeBases/Show', [
            'kb' => $kb,
            'kbUploadMaxKb' => $kbUploadMaxKb,
            'kbUploadMaxMb' => round($kbUploadMaxKb / 1024, 1),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate(['name' => ['required', 'string', 'max:128']]);
        AiKnowledgeBase::create(array_merge($validated, ['workspace_id' => $workspaceId]));

        return back()->with('success', 'Knowledge base created.');
    }

    public function update(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);
        $validated = $request->validate(['name' => ['required', 'string', 'max:128']]);
        $kb->update($validated);

        return back()->with('success', 'Knowledge base updated.');
    }

    public function destroy(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);

        $documents = $kb->documents()->get(['id', 'source_type', 'source_ref']);
        $documentIds = $documents->pluck('id')->all();
        $filePaths = $documents
            ->where('source_type', 'file')
            ->pluck('source_ref')
            ->filter()
            ->values()
            ->all();

        // Qdrant is external to the database, so remove its vectors before the
        // relational records. A failed vector cleanup leaves the KB intact and
        // allows the client to safely retry the deletion.
        foreach ($documentIds as $documentId) {
            $this->embeddings->deleteDocumentEmbeddings($documentId);
        }

        DB::transaction(function () use ($kb, $documentIds): void {
            // Existing chatbots continue in prompt-only mode after their KB is gone.
            $kb->chatbots()->update(['ai_kb_id' => null]);

            $chunks = AiKbChunk::query()->where('kb_id', $kb->id);
            if ($documentIds !== []) {
                $chunks->orWhereIn('document_id', $documentIds);
            }
            $chunks->delete();

            $kb->documents()->delete();
            $kb->delete();
        });

        $this->deleteStoredFiles($filePaths);

        return to_route('client.ai.knowledge-bases.index')
            ->with('success', 'Knowledge base deleted.');
    }

    public function addDocument(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);

        $validated = $request->validate([
            'source_type' => ['required', 'in:file,url,text,sitemap,faq'],
            'source_ref' => $this->sourceRefRules((string) $request->input('source_type')),
            'title' => ['nullable', 'string', 'max:256'],
        ]);

        // Handle file upload
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => [
                    'file',
                    'max:'.$this->kbUploadMaxKb(),
                    'mimes:pdf,txt,md,csv,docx,doc,xlsx,xls,json',
                ],
            ]);
            $file = $request->file('file');
            $diskName = $this->storage->diskName();
            $path = $this->storage->prefixedPath('kb-docs/'.$file->hashName());
            $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));
            $validated['source_ref'] = $path;
            $validated['title'] = $validated['title'] ?? $file->getClientOriginalName();
        }

        $doc = AiKbDocument::create(array_merge($validated, ['kb_id' => $kb->id, 'status' => 'pending']));

        try {
            IndexDocumentJob::dispatch($doc->id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['source_type' => $e->getMessage()]);
        }

        return back()->with('success', 'Document queued for indexing.');
    }

    public function reindex(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $document->update(['status' => 'pending', 'error_message' => null]);

        try {
            IndexDocumentJob::dispatch($document->id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('success', 'Re-indexing queued.');
    }

    public function destroyDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $filePath = $document->source_type === 'file' ? $document->source_ref : null;

        $this->embeddings->deleteDocumentEmbeddings($document->id);
        DB::transaction(function () use ($document): void {
            $document->chunks()->delete();
            $document->delete();
        });

        $this->deleteStoredFiles(array_filter([$filePath]));

        return back()->with('success', 'Document removed.');
    }

    private function deleteStoredFiles(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        try {
            $this->storage->disk()->delete($paths);
        } catch (\Throwable $exception) {
            Log::warning('Knowledge base file cleanup failed after database deletion.', [
                'file_count' => count($paths),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function authorise(Request $request, AiKnowledgeBase $kb): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $kb->workspace_id === (int) $workspaceId, 403);
    }

    private function kbUploadMaxKb(): int
    {
        $appMaxKb = 20 * 1024;
        $serverMaxKb = min(
            $this->iniSizeToKb(ini_get('upload_max_filesize')),
            $this->iniSizeToKb(ini_get('post_max_size')),
        );

        // Leave room for multipart form overhead so a file at the exact PHP
        // limit does not get rejected by the web server before Laravel sees it.
        $serverMaxKb = max(1024, $serverMaxKb - 512);

        return min($appMaxKb, $serverMaxKb);
    }

    private function iniSizeToKb(string|false $value): int
    {
        if ($value === false || trim($value) === '') {
            return PHP_INT_MAX;
        }

        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024),
            'm' => (int) ($number * 1024),
            'k' => (int) $number,
            default => (int) ceil($number / 1024),
        };
    }

    private function sourceRefRules(string $sourceType): array
    {
        return match ($sourceType) {
            'url', 'sitemap' => ['required', 'url', 'max:2048'],
            'text' => ['required', 'string', 'max:200000'],
            'faq' => ['required', 'string', 'max:200000'],
            'file' => ['nullable', 'string', 'max:512'],
            default => ['nullable', 'string', 'max:512'],
        };
    }
}
