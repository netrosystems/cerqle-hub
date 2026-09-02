<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\AI\Services\ProviderErrorPresenter;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\HTMLToMarkdown\HtmlConverter;
use Smalot\PdfParser\Parser;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $documentId) {}

    public function handle(LlmGateway $llm, EmbeddingStore $store, StorageManager $storage): void
    {
        $doc = AiKbDocument::with('chunks')->find($this->documentId);
        if (! $doc) {
            return;
        }

        $doc->update(['status' => 'indexing', 'error_message' => null]);

        try {
            $text = $this->extractText($doc, $storage);
            $chunks = $this->chunk($text);

            if (empty($chunks) && $doc->source_type !== 'sitemap') {
                throw new \RuntimeException(match ($doc->source_type) {
                    'url' => 'URL indexing failed: no readable text was found on this page.',
                    'file' => 'Document indexing failed: the uploaded file could not be read or contained no extractable text.',
                    default => 'Document indexing failed: no readable text was found.',
                });
            }

            // Remove old vectors before deleting the relational chunks. Without
            // this, re-indexing leaves stale Qdrant points that can be returned
            // for a knowledge base even though their document no longer exists.
            $store->deleteDocumentEmbeddings($doc->id);

            // Remove old chunks
            $doc->chunks()->delete();

            $kb = $doc->knowledgeBase ?? $doc->load('knowledgeBase')->knowledgeBase;
            $kbId = $kb?->id ?? 0;

            $chunkModels = [];
            foreach ($chunks as $i => $chunkText) {
                $chunkModels[] = AiKbChunk::create([
                    'kb_id' => $kbId,
                    'document_id' => $doc->id,
                    'ord' => $i,
                    'content' => $chunkText,
                    'tokens' => (int) (strlen($chunkText) / 4),
                ]);
            }

            // Embed all chunks.
            //
            // A missing embedding provider (Anthropic-only or none configured) is a
            // non-fatal condition: the document is still indexed as plain text and we
            // log it so operators can see RAG won't work until a provider is added.
            //
            // A transient embedding API error, by contrast, is allowed to propagate so
            // the queue retries — rather than silently marking the document "indexed"
            // with no vectors.
            $workspaceId = $kb?->workspace_id ?? 0;

            if ($workspaceId && ! empty($chunkModels)) {
                if ($this->embedProviderAvailable($workspaceId)) {
                    foreach (array_chunk($chunkModels, 20) as $batch) {
                        $texts = array_map(fn ($c) => $c->content, $batch);
                        $embeddings = $llm->embed($workspaceId, $texts);

                        foreach ($batch as $j => $chunk) {
                            if (isset($embeddings[$j])) {
                                $store->storeEmbedding($chunk, $embeddings[$j]);
                            }
                        }
                    }
                } else {
                    Log::warning('IndexDocumentJob: indexed without embeddings — no embedding-capable provider configured', [
                        'document_id' => $doc->id,
                        'kb_id' => $kbId,
                        'workspace_id' => $workspaceId,
                    ]);
                }
            }

            $doc->update([
                'status' => 'indexed',
                'error_message' => null,
                'last_indexed_at' => now(),
                'tokens' => array_sum(array_map(fn ($c) => $c->tokens, $chunkModels)),
            ]);
        } catch (\Throwable $e) {
            $doc->update([
                'status' => 'error',
                'error_message' => $this->safeErrorMessage($e),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        AiKbDocument::whereKey($this->documentId)->update([
            'status' => 'error',
            'error_message' => $this->safeErrorMessage($exception),
        ]);
    }

    private function safeErrorMessage(\Throwable $exception): string
    {
        $presented = ProviderErrorPresenter::present($exception);
        if ($presented['code'] !== 'provider_request_failed') {
            return $presented['message'];
        }

        $message = strtolower($exception->getMessage());
        foreach (['url indexing failed', 'sitemap indexing failed', 'document indexing failed'] as $safeMarker) {
            if (str_contains($message, $safeMarker)) {
                return $exception->getMessage();
            }
        }

        foreach (['openai', 'anthropic', 'gemini', 'deepseek', 'embedding', 'ai provider'] as $providerMarker) {
            if (str_contains($message, $providerMarker)) {
                return $presented['message'];
            }
        }

        return 'Document indexing failed. Check the source file or URL and the server logs, then try re-indexing.';
    }

    /** True when the workspace has an embedding-capable provider (OpenAI/Gemini). */
    private function embedProviderAvailable(int $workspaceId): bool
    {
        // Any failure to RESOLVE a provider (none configured, orphaned workspace,
        // malformed config) is treated as "no embeddings" — a non-fatal condition,
        // so the document still indexes as plain text. This deliberately does NOT
        // swallow errors from the actual embed() call below, which must still
        // propagate so the queue retries rather than indexing with no vectors.
        try {
            LlmManager::forWorkspaceEmbed($workspaceId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractText(AiKbDocument $doc, StorageManager $storage): string
    {
        $text = match ($doc->source_type) {
            'text' => $doc->source_ref ?? '',
            'url' => $this->fetchUrl($doc->source_ref ?? ''),
            'file' => $this->readFile($doc->source_ref ?? '', $storage),
            'faq' => $this->formatFaq($doc->source_ref ?? ''),
            'sitemap' => $this->processSitemap($doc),
            default => '',
        };

        return $this->normaliseExtractedText($text);
    }

    private function fetchUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }
        $resp = Http::withHeaders([
            'User-Agent' => 'CerqleKnowledgeIndexer/1.0 (+https://cerqle.ai)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.7',
        ])->retry(2, 500)->timeout(30)->get($url);
        if (! $resp->successful()) {
            throw new \RuntimeException('URL indexing failed: '.$url.' returned HTTP '.$resp->status().'.');
        }
        $html = $this->removeHtmlNoise($resp->body());
        // Convert HTML to Markdown using league/html-to-markdown, then strip remaining tags
        if (class_exists(HtmlConverter::class)) {
            $converter = new HtmlConverter(['strip_tags' => true]);

            return $converter->convert($html);
        }

        return strip_tags($html);
    }

    /**
     * Read an uploaded document back from the active storage disk.
     *
     * The stored source_ref is a disk-relative key (e.g. "uploads/kb-docs/x.pdf"),
     * NOT an absolute local path, so it must be read through the Storage disk —
     * which works for both the local "public" disk and cloud disks (S3/Spaces/Wasabi).
     */
    private function readFile(string $path, StorageManager $storage): string
    {
        if ($path === '') {
            return '';
        }

        $disk = $storage->disk();
        if (! $disk->exists($path)) {
            return '';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            // Smalot's parser needs a real local file, so stream the (possibly remote)
            // object into a temp file before parsing, then clean it up.
            $tmp = tempnam(sys_get_temp_dir(), 'kbpdf_');
            try {
                file_put_contents($tmp, $disk->get($path));
                $parser = new Parser;

                return $parser->parseFile($tmp)->getText();
            } finally {
                @unlink($tmp);
            }
        }

        if ($ext === 'docx') {
            return $this->readDocx((string) ($disk->get($path) ?? ''));
        }

        if ($ext === 'doc') {
            throw new \RuntimeException('Document indexing failed: legacy .doc files are not supported. Please convert this file to .docx, PDF, or TXT and upload it again.');
        }

        return (string) ($disk->get($path) ?? '');
    }

    private function readDocx(string $contents): string
    {
        if ($contents === '' || ! class_exists(\ZipArchive::class)) {
            return '';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'kbdocx_');
        try {
            file_put_contents($tmp, $contents);

            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                return '';
            }

            $parts = [];
            foreach (['word/document.xml', 'word/footnotes.xml', 'word/endnotes.xml'] as $entry) {
                $xml = $zip->getFromName($entry);
                if ($xml === false) {
                    continue;
                }

                // Preserve paragraph/table-cell boundaries before stripping XML.
                $xml = preg_replace('/<\/w:p>/i', "\n", $xml) ?? $xml;
                $xml = preg_replace('/<\/w:tr>/i', "\n", $xml) ?? $xml;
                $xml = preg_replace('/<\/w:tc>/i', "\t", $xml) ?? $xml;
                $parts[] = strip_tags($xml);
            }

            $zip->close();

            return implode("\n\n", $parts);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Turn the FAQ payload (a JSON array of {question, answer} pairs produced by the
     * UI) into clean, embeddable text. Falls back to the raw string if it isn't JSON.
     */
    private function formatFaq(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // Not JSON — treat as plain text.
            return $raw;
        }

        $parts = [];
        foreach ($decoded as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $q = trim((string) ($pair['question'] ?? ''));
            $a = trim((string) ($pair['answer'] ?? ''));
            if ($q === '' && $a === '') {
                continue;
            }
            $parts[] = "Q: {$q}\nA: {$a}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Parse a sitemap and fan out one lightweight child job per page URL.
     *
     * This job stays cheap on purpose: it only fetches + parses the XML and
     * enqueues child "url" documents. The actual page crawling/embedding happens
     * in those child jobs on the queue, so the originating web request never
     * blocks on hundreds of HTTP fetches (which previously caused a 502 when the
     * queue ran synchronously).
     *
     * Handles both <urlset> (a flat list of pages) and <sitemapindex> (a list of
     * nested sitemaps, e.g. Yoast/WordPress) — for the latter, each nested sitemap
     * is enqueued as its own "sitemap" child and expanded recursively.
     */
    private function processSitemap(AiKbDocument $doc): string
    {
        $sitemapUrl = $doc->source_ref ?? '';
        if (empty($sitemapUrl)) {
            return '';
        }
        $resp = Http::withHeaders([
            'User-Agent' => 'CerqleKnowledgeIndexer/1.0 (+https://cerqle.ai)',
            'Accept' => 'application/xml,text/xml,text/plain;q=0.9,*/*;q=0.7',
        ])->retry(2, 500)->timeout(20)->get($sitemapUrl);
        if (! $resp->successful()) {
            throw new \RuntimeException('Sitemap indexing failed: '.$sitemapUrl.' returned HTTP '.$resp->status().'.');
        }

        try {
            $xml = simplexml_load_string($resp->body());
            if ($xml === false) {
                throw new \RuntimeException('Unparseable sitemap XML');
            }

            // <sitemapindex> → nested sitemaps; <urlset> → page URLs.
            $isIndex = isset($xml->sitemap);
            $childType = $isIndex ? 'sitemap' : 'url';

            $locs = [];
            foreach (($isIndex ? $xml->sitemap : $xml->url) as $node) {
                $loc = trim((string) $node->loc);
                if ($loc !== '') {
                    $locs[$loc] = true; // dedupe by URL
                }
            }

            if ($locs === []) {
                throw new \RuntimeException('Sitemap indexing failed: no page URLs were found in '.$sitemapUrl.'.');
            }

            foreach (array_slice(array_keys($locs), 0, 200) as $loc) {
                $child = AiKbDocument::create([
                    'kb_id' => $doc->kb_id,
                    'title' => $loc,
                    'source_type' => $childType,
                    'source_ref' => $loc,
                    'status' => 'pending',
                ]);
                static::dispatch($child->id)->onQueue('ai');
            }
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'sitemap indexing failed')) {
                throw $exception;
            }

            // Malformed sitemap; fall back to fetching the URL as HTML
            return $this->fetchUrl($sitemapUrl);
        }

        return '';
    }

    private function removeHtmlNoise(string $html): string
    {
        // Script/style/svg blobs can contain huge minified strings that are not
        // useful knowledge-base content and can exceed embedding model limits.
        $html = preg_replace('/<(script|style|noscript|svg|canvas|iframe)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;

        return $html;
    }

    private function normaliseExtractedText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function chunk(string $text, int $size = 700, int $overlap = 80, int $maxChars = 6000): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $chunks = [];
        $i = 0;

        while ($i < count($words)) {
            $slice = array_slice($words, $i, $size);
            $chunk = trim(implode(' ', $slice));
            if ($chunk !== '') {
                foreach ($this->splitOversizedChunk($chunk, $maxChars) as $part) {
                    $chunks[] = $part;
                }
            }
            $i += ($size - $overlap);
        }

        return array_values(array_filter($chunks));
    }

    private function splitOversizedChunk(string $chunk, int $maxChars): array
    {
        if (strlen($chunk) <= $maxChars) {
            return [$chunk];
        }

        $parts = [];
        $remaining = $chunk;

        while (strlen($remaining) > $maxChars) {
            $candidate = substr($remaining, 0, $maxChars);
            $splitAt = max(strrpos($candidate, "\n") ?: 0, strrpos($candidate, '. ') ?: 0, strrpos($candidate, ' ') ?: 0);
            if ($splitAt < (int) ($maxChars * 0.5)) {
                $splitAt = $maxChars;
            }

            $parts[] = trim(substr($remaining, 0, $splitAt));
            $remaining = trim(substr($remaining, $splitAt));
        }

        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return array_values(array_filter($parts));
    }
}
