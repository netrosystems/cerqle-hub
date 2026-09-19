<?php

namespace Tests\Unit;

use App\Services\Media\AttachmentService;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentServiceMediaTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ios_m4a_with_generic_video_mime_is_normalised_to_audio(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->create('voice.m4a', 20, 'video/mp4');

        $upload = new AttachmentService(app(StorageManager::class));
        $result = $upload->processUpload($file);

        $this->assertSame('audio', $result['type']);
        $this->assertSame('audio/mp4', $result['mime_type']);
    }
}
