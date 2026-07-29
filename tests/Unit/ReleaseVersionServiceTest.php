<?php

namespace Tests\Unit;

use App\Services\ReleaseVersionService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReleaseVersionServiceTest extends TestCase
{
    private string $releaseDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseDirectory = storage_path('framework/testing/releases-'.uniqid());
        config([
            'release.base_version' => '2.4.9',
            'release.state_path' => $this->releaseDirectory.'/current.json',
            'release.history_path' => $this->releaseDirectory.'/history.json',
            'release.history_limit' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->releaseDirectory);

        parent::tearDown();
    }

    public function test_it_increments_patch_versions_and_keeps_release_history(): void
    {
        $service = app(ReleaseVersionService::class);

        $this->assertSame('2.4.9', $service->current()['version']);
        $this->assertSame('2.4.10', $service->record()['version']);
        $this->assertSame('2.4.11', $service->record()['version']);
        $this->assertSame('2.4.11', $service->current()['version']);

        $history = json_decode((string) File::get(config('release.history_path')), true);

        $this->assertCount(2, $history);
        $this->assertSame('2.4.11', $history[0]['version']);
        $this->assertSame('2.4.10', $history[1]['version']);
    }

    public function test_it_can_record_an_explicit_release_version(): void
    {
        $release = app(ReleaseVersionService::class)->record('v3.1.0');

        $this->assertSame('3.1.0', $release['version']);
        $this->assertNotEmpty($release['deployed_at']);
    }
}
