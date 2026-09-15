<?php

namespace Tests\Feature\Security;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SitemapBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_and_retried_sitemap_enqueues_each_child_only_once(): void
    {
        Queue::fake();
        $doc = $this->document();
        Http::fake(['https://8.8.8.8/*' => fn () => Http::response('<urlset><url><loc>https://8.8.8.8/page</loc></url><url><loc>https://8.8.8.8/page</loc></url></urlset>')]);
        $this->parse($doc);
        $this->parse($doc);
        $this->assertSame(1, AiKbDocument::where('crawl_root_id', $doc->id)->count());
        Queue::assertPushed(IndexDocumentJob::class, 1);
    }

    public function test_root_budget_applies_to_nested_sitemaps(): void
    {
        Queue::fake();
        $root = $this->document();
        for ($i = 0; $i < 199; $i++) {
            $root->knowledgeBase->documents()->create(['source_type' => 'url', 'source_ref' => 'https://8.8.8.8/existing/'.$i, 'crawl_root_id' => $root->id]);
        }
        $nested = $root->knowledgeBase->documents()->create(['source_type' => 'sitemap', 'source_ref' => 'https://8.8.8.8/nested.xml', 'crawl_root_id' => $root->id, 'sitemap_depth' => 1]);
        Http::fake(['https://8.8.8.8/*' => Http::response('<urlset><url><loc>https://8.8.8.8/new</loc></url></urlset>')]);
        $this->parse($nested);
        $this->assertSame(200, AiKbDocument::where('crawl_root_id', $root->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_depth_limit_prevents_a_network_request(): void
    {
        $doc = $this->document();
        $doc->update(['sitemap_depth' => 3]);
        Http::fake();
        $this->expectException(\RuntimeException::class);
        try {
            $this->parse($doc);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_xml_entities_and_private_child_urls_fail_closed(): void
    {
        Queue::fake();
        $doc = $this->document();
        Http::fake(['https://8.8.8.8/*' => Http::sequence()
            ->push('<!DOCTYPE urlset [<!ENTITY leak SYSTEM "file:///etc/passwd">]><urlset/>')
            ->push('<urlset><url><loc>http://127.0.0.1/private</loc></url></urlset>')]);
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->parse($doc);
                $this->fail('Unsafe sitemap accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Sitemap indexing failed:', $error->getMessage());
            }
        }
        Queue::assertNothingPushed();
        $this->assertSame(1, AiKbDocument::count());
    }

    private function document(): AiKbDocument
    {
        return AiKnowledgeBase::factory()->create()->documents()->create(['source_type' => 'sitemap', 'source_ref' => 'https://8.8.8.8/sitemap.xml', 'status' => 'pending']);
    }

    private function parse(AiKbDocument $doc): void
    {
        (new \ReflectionMethod(IndexDocumentJob::class, 'processSitemap'))->invoke(new IndexDocumentJob($doc->id), $doc);
    }
}
