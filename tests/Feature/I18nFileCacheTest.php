<?php

namespace Tests\Feature;

use App\Services\I18n\I18nFileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class I18nFileCacheTest extends TestCase
{
    private string $code = 'zz_cache_test';

    public function test_editing_a_locale_file_is_picked_up_without_clearing_the_cache(): void
    {
        $service = app(I18nFileService::class);
        $path = $service->path($this->code);

        File::put($path, json_encode(['social' => ['greeting' => 'Hello']]));
        $this->assertSame('Hello', $service->getFlatDictionary($this->code)['social.greeting'] ?? null);

        // Simulate a deploy or a developer adding a key: the file changes, but
        // nothing bumps the stored i18n version because that only moves when a
        // translation is edited through the admin UI. Before the file's own
        // timestamp was part of the cache key, the new key stayed invisible
        // for an hour and the UI rendered its raw name instead.
        touch($path, time() + 5);
        File::put($path, json_encode(['social' => ['greeting' => 'Hello', 'added_later' => 'New copy']]));
        touch($path, time() + 5);

        $dictionary = $service->getFlatDictionary($this->code);
        $this->assertSame('New copy', $dictionary['social.added_later'] ?? null);
    }

    protected function tearDown(): void
    {
        File::delete(app(I18nFileService::class)->path($this->code));
        parent::tearDown();
    }
}
