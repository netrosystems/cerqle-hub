<?php

namespace App\Services\Media;

use App\Services\StorageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttachmentService
{
    /** Supported MIME types / extensions for messaging attachments */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,webp,gif,heic,heif,mp3,aac,m4a,amr,ogg,oga,wav,webm,mp4,mov,3gp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';

    public const MAX_FILE_KILOBYTES = 10240; // 10 MB limit

    public function __construct(
        private readonly StorageManager $storageManager,
    ) {}

    /**
     * Process an incoming attachment upload, auto-converting Apple HEIC/HEIF
     * photos to standard JPEGs when possible, and storing in configured media disk.
     *
     * @return array{
     *     path: string,
     *     url: string,
     *     filename: string,
     *     mime_type: string,
     *     type: string,
     *     size_bytes: int,
     *     is_converted_heic: bool
     * }
     */
    public function processUpload(UploadedFile $file, string $directory = 'message-media'): array
    {
        $originalName = $file->getClientOriginalName();
        $rawMime = $file->getMimeType() ?? 'application/octet-stream';
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($originalName, PATHINFO_EXTENSION));
        $sizeBytes = (int) $file->getSize();

        $isHeic = $this->isHeic($file);
        $convertedHeic = false;

        if ($isHeic) {
            $convertedPath = $this->attemptHeicConversion($file->getRealPath());
            if ($convertedPath && file_exists($convertedPath)) {
                $hash = Str::random(40).'.jpg';
                $storedPath = $this->storageManager->prefixedPath("{$directory}/{$hash}");
                $this->storageManager->disk()->put(
                    $storedPath,
                    (string) file_get_contents($convertedPath)
                );
                @unlink($convertedPath);

                $convertedHeic = true;
                $url = $this->storageManager->disk()->url($storedPath);
                $convertedFilename = pathinfo($originalName, PATHINFO_FILENAME).'.jpg';

                return [
                    'path' => $storedPath,
                    'url' => $url,
                    'filename' => $convertedFilename,
                    'mime_type' => 'image/jpeg',
                    'type' => 'image',
                    'size_bytes' => (int) $this->storageManager->disk()->size($storedPath),
                    'is_converted_heic' => true,
                ];
            }
        }

        // Standard upload (or HEIC fallback as document if server lacks HEIC delegate)
        $ext = $extension !== '' ? $extension : 'bin';
        $hashName = Str::random(40).'.'.$ext;
        $storedPath = $this->storageManager->prefixedPath("{$directory}/{$hashName}");
        $this->storageManager->disk()->putFileAs(dirname($storedPath), $file, basename($storedPath));
        $url = $this->storageManager->disk()->url($storedPath);

        $inferredType = $this->inferMessageType($rawMime, $extension);
        if ($isHeic && ! $convertedHeic) {
            // Server could not decode HEIC into JPEG -> treat safely as a document attachment
            $inferredType = 'document';
        }

        return [
            'path' => $storedPath,
            'url' => $url,
            'filename' => $originalName,
            'mime_type' => $rawMime,
            'type' => $inferredType,
            'size_bytes' => $sizeBytes,
            'is_converted_heic' => false,
        ];
    }

    /**
     * Check if the uploaded file is an Apple HEIC/HEIF image by MIME, extension,
     * or ISO BMFF magic bytes.
     */
    public function isHeic(UploadedFile|string $file): bool
    {
        $realPath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $extension = strtolower($file instanceof UploadedFile ? $file->getClientOriginalExtension() : pathinfo($file, PATHINFO_EXTENSION));
        $mime = $file instanceof UploadedFile ? ($file->getMimeType() ?? '') : '';

        if (in_array($extension, ['heic', 'heif', 'heifs', 'heic-sequence', 'heif-sequence'], true)) {
            return true;
        }

        if (in_array(strtolower($mime), ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
            return true;
        }

        // Inspect header magic bytes for ISO BMFF ftyp box (bytes 4-8 = 'ftyp')
        if ($realPath && is_readable($realPath) && filesize($realPath) >= 12) {
            $handle = @fopen($realPath, 'rb');
            if ($handle) {
                $header = (string) fread($handle, 12);
                fclose($handle);

                if (strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp') {
                    $brand = strtolower(substr($header, 8, 4));
                    if (in_array($brand, ['heic', 'heix', 'hevc', 'heim', 'heis', 'mif1', 'msf1'], true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Attempt converting a HEIC file to JPEG using Imagick.
     * Returns the temporary JPEG file path on success, or null on failure.
     */
    public function attemptHeicConversion(string $sourcePath, int $quality = 90): ?string
    {
        if (! class_exists('\Imagick')) {
            Log::info('AttachmentService: Imagick extension not installed; skipping HEIC auto-conversion.');

            return null;
        }

        try {
            /** @phpstan-ignore-next-line */
            $imagick = new \Imagick;
            $imagick->readImage($sourcePath);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($quality);

            // Strip metadata profiles if needed and fix orientation
            if (method_exists($imagick, 'autoOrient')) {
                $imagick->autoOrient();
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'heic_conv_').'.jpg';
            $imagick->writeImage($tempPath);
            $imagick->clear();
            $imagick->destroy();

            return $tempPath;
        } catch (\Throwable $e) {
            Log::warning('AttachmentService: HEIC conversion failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Map MIME type / extension to message type category.
     */
    public function inferMessageType(string $mime, string $extension = ''): string
    {
        $mime = strtolower($mime);
        $ext = strtolower($extension);

        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true)) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', '3gp', 'webm'], true)) {
            return 'video';
        }

        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'aac', 'm4a', 'amr', 'ogg', 'oga', 'wav'], true)) {
            return 'audio';
        }

        return 'document';
    }

    /**
     * Human-readable file size formatter.
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
