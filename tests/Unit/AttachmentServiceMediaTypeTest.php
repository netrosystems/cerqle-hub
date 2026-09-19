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

    public function test_jpeg_bytes_with_a_stale_heic_filename_are_not_treated_as_heic(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jpeg_heic_');
        file_put_contents($path, hex2bin('ffd8ffe000104a46494600010100000100010000ffd9'));
        $file = new UploadedFile($path, 'camera.heic', 'image/heic', null, true);
        $service = new AttachmentService(app(StorageManager::class));

        try {
            $this->assertFalse($service->isHeic($file));
            $prepared = $service->prepareForWhatsapp($file, [
                'path' => 'message-media/camera.jpg',
                'mime_type' => 'image/jpeg',
                'type' => 'image',
                'is_converted_heic' => false,
            ]);
            $this->assertSame('image/jpeg', $prepared['mime_type']);
            $this->assertFalse($prepared['temporary']);
            $this->assertSame($file->getRealPath(), $prepared['path']);
        } finally {
            @unlink($path);
        }
    }

    public function test_whatsapp_webp_is_prepared_as_a_temporary_jpeg(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'webp_');
        file_put_contents($source, base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEALmk0mk0iIiIiIgBoSygABc6zbAAA'));
        $converted = tempnam(sys_get_temp_dir(), 'jpeg_').'.jpg';
        file_put_contents($converted, hex2bin('ffd8ffe000104a46494600010100000100010000ffd9'));
        $file = new UploadedFile($source, 'photo.webp', 'image/webp', null, true);
        $service = new class(app(StorageManager::class), $converted) extends AttachmentService
        {
            public function __construct(StorageManager $storageManager, private string $converted)
            {
                parent::__construct($storageManager);
            }

            public function attemptImageConversionToJpeg(string $sourcePath, int $quality = 90): ?string
            {
                return $this->converted;
            }
        };

        try {
            $prepared = $service->prepareForWhatsapp($file, [
                'path' => 'message-media/photo.webp',
                'mime_type' => 'image/webp',
                'type' => 'image',
                'is_converted_heic' => false,
            ]);
            $this->assertSame('image/jpeg', $prepared['mime_type']);
            $this->assertTrue($prepared['temporary']);
            $this->assertSame($converted, $prepared['path']);
        } finally {
            @unlink($source);
            @unlink($converted);
        }
    }

    public function test_external_webp_is_replaced_with_a_persisted_jpeg(): void
    {
        Storage::fake('public');
        $source = tempnam(sys_get_temp_dir(), 'webp_');
        file_put_contents($source, base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEALmk0mk0iIiIiIgBoSygABc6zbAAA'));
        $converted = tempnam(sys_get_temp_dir(), 'jpeg_').'.jpg';
        $jpeg = hex2bin('ffd8ffe000104a46494600010100000100010000ffd9');
        file_put_contents($converted, $jpeg);
        $file = new UploadedFile($source, 'photo.webp', 'image/webp', null, true);
        Storage::disk('public')->put('message-media/original.webp', (string) file_get_contents($source));
        $service = new class(app(StorageManager::class), $converted) extends AttachmentService
        {
            public function __construct(StorageManager $storageManager, private string $converted)
            {
                parent::__construct($storageManager);
            }

            public function attemptImageConversionToJpeg(string $sourcePath, int $quality = 90): ?string
            {
                return $this->converted;
            }
        };

        try {
            $result = $service->normaliseExternalImage($file, [
                'path' => 'message-media/original.webp',
                'url' => Storage::disk('public')->url('message-media/original.webp'),
                'filename' => 'photo.webp',
                'mime_type' => 'image/webp',
                'type' => 'image',
                'size_bytes' => (int) filesize($source),
                'is_converted_heic' => false,
            ]);

            $this->assertSame('image/jpeg', $result['mime_type']);
            $this->assertSame('photo.webp', $result['filename']);
            $this->assertStringEndsWith('.jpg', $result['path']);
            Storage::disk('public')->assertMissing('message-media/original.webp');
            Storage::disk('public')->assertExists($result['path']);
            $this->assertSame($jpeg, Storage::disk('public')->get($result['path']));
        } finally {
            @unlink($source);
            @unlink($converted);
        }
    }
}
