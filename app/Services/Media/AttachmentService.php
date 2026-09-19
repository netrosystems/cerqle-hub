<?php

namespace App\Services\Media;

use App\Services\StorageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AttachmentService
{
    /** Supported MIME types / extensions for messaging attachments */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,webp,gif,heic,heif,mp3,aac,m4a,amr,ogg,oga,opus,weba,wav,webm,mp4,mov,3gp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';

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
        $clientExtension = strtolower($file->getClientOriginalExtension() ?: pathinfo($originalName, PATHINFO_EXTENSION));
        $rawMime = $this->normaliseMimeType(
            $file->getMimeType() ?? 'application/octet-stream',
            $clientExtension,
        );
        $extension = SafeUploadName::extension($file);
        $sizeBytes = (int) $file->getSize();

        $isHeic = $this->isHeic($file);

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

        $inferredType = $this->inferMessageType($rawMime, $clientExtension ?: $extension);
        if ($isHeic) {
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

        // Some iOS/browser uploads retain a .heic filename after converting the
        // bytes to JPEG. Content detection must win over that stale extension.
        if ($file instanceof UploadedFile && in_array(strtolower($mime), [
            'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif',
        ], true)) {
            return false;
        }

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
     * Attempt converting a HEIC file to JPEG using Imagick or an available
     * server-side converter.
     * Returns the temporary JPEG file path on success, or null on failure.
     */
    public function attemptHeicConversion(string $sourcePath, int $quality = 90): ?string
    {
        return $this->attemptImageConversionToJpeg($sourcePath, $quality);
    }

    /** Convert any browser-readable but WhatsApp-incompatible image to JPEG. */
    public function attemptImageConversionToJpeg(string $sourcePath, int $quality = 90): ?string
    {
        return $this->attemptImagickConversion($sourcePath, $quality)
            ?? $this->attemptCommandConversion($sourcePath, $quality);
    }

    /**
     * Prepare an already validated upload for the WhatsApp media endpoint.
     * The returned temporary file, when present, is owned by the caller.
     *
     * @param  array{path:string,mime_type:string,type:string,is_converted_heic:bool}  $upload
     * @return array{path:string,mime_type:string,temporary:bool}
     */
    public function prepareForWhatsapp(UploadedFile $file, array $upload): array
    {
        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            throw new \RuntimeException('The uploaded media file is no longer available.');
        }
        $mimeType = $this->normaliseMimeType(
            $file->getMimeType() ?? $upload['mime_type'],
            strtolower($file->getClientOriginalExtension()),
        );

        if ($upload['type'] !== 'image') {
            return ['path' => $sourcePath, 'mime_type' => $mimeType, 'temporary' => false];
        }

        if (in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
            return [
                'path' => $sourcePath,
                'mime_type' => $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType,
                'temporary' => false,
            ];
        }

        if ($upload['is_converted_heic']) {
            $convertedPath = $this->temporaryJpegPath('wa_image_');
            $contents = $this->storageManager->disk()->get($upload['path']);
            if (file_put_contents($convertedPath, $contents) === false) {
                @unlink($convertedPath);
                throw new \RuntimeException('Could not prepare the converted image for delivery.');
            }
        } else {
            $convertedPath = $this->attemptImageConversionToJpeg($sourcePath);
        }

        if (! $convertedPath || ! $this->validConvertedImage($convertedPath)) {
            throw new \RuntimeException('This image must be converted to JPEG or PNG before delivery, but no working server converter was available.');
        }

        return ['path' => $convertedPath, 'mime_type' => 'image/jpeg', 'temporary' => true];
    }

    /**
     * Persist a provider-safe JPEG for URL-based social sends while keeping
     * browser-native JPEG/PNG uploads untouched.
     *
     * @param  array{path:string,url:string,filename:string,mime_type:string,type:string,size_bytes:int,is_converted_heic:bool}  $upload
     * @return array{path:string,url:string,filename:string,mime_type:string,type:string,size_bytes:int,is_converted_heic:bool}
     */
    public function normaliseExternalImage(UploadedFile $file, array $upload): array
    {
        if ($upload['type'] !== 'image' || in_array($upload['mime_type'], ['image/jpeg', 'image/jpg', 'image/png'], true)) {
            return $upload;
        }

        $prepared = $this->prepareForWhatsapp($file, $upload);
        if (! $prepared['temporary']) {
            return $upload;
        }

        try {
            $contents = file_get_contents($prepared['path']);
            if ($contents === false) {
                throw new \RuntimeException('Could not read the converted image.');
            }

            $storedPath = dirname($upload['path']).'/'.Str::random(40).'.jpg';
            if (! $this->storageManager->disk()->put($storedPath, $contents)) {
                throw new \RuntimeException('Could not store the converted image.');
            }

            $this->storageManager->disk()->delete($upload['path']);

            return array_merge($upload, [
                'path' => $storedPath,
                'url' => $this->storageManager->disk()->url($storedPath),
                'mime_type' => 'image/jpeg',
                'size_bytes' => strlen($contents),
            ]);
        } finally {
            @unlink($prepared['path']);
        }
    }

    private function attemptImagickConversion(string $sourcePath, int $quality): ?string
    {
        if (! class_exists('\Imagick')) {
            return null;
        }

        $tempPath = $this->temporaryJpegPath('heic_conv_');
        try {
            $imagick = new \Imagick;
            $imagick->readImage($sourcePath);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($quality);

            // Strip metadata profiles if needed and fix orientation
            $imagick->autoOrient();

            $imagick->writeImage($tempPath);
            $imagick->clear();
            $imagick->destroy();

            return $this->validConvertedImage($tempPath) ? $tempPath : null;
        } catch (\Throwable $e) {
            @unlink($tempPath);
            Log::warning('AttachmentService: Could not convert HEIC image to JPEG: '.$e->getMessage());

            return null;
        }
    }

    private function attemptCommandConversion(string $sourcePath, int $quality): ?string
    {
        foreach ($this->conversionCommands($sourcePath, $quality) as [$name, $command, $target]) {
            try {
                $process = new Process($command);
                $process->setTimeout(60);
                $process->run();

                if ($process->isSuccessful() && $this->validConvertedImage($target)) {
                    return $target;
                }

                @unlink($target);
            } catch (\Throwable $e) {
                @unlink($target);
                Log::debug('AttachmentService: HEIC converter unavailable.', [
                    'converter' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('AttachmentService: No available HEIC converter produced a JPEG preview.');

        return null;
    }

    /** @return array<int, array{0:string, 1:array<int, string>, 2:string}> */
    private function conversionCommands(string $sourcePath, int $quality): array
    {
        $quality = (string) max(1, min(100, $quality));
        $magickTarget = $this->temporaryJpegPath('heic_magick_');
        $convertTarget = $this->temporaryJpegPath('heic_convert_');
        $heifTarget = $this->temporaryJpegPath('heic_heif_');
        $ffmpegTarget = $this->temporaryJpegPath('heic_ffmpeg_');

        return [
            ['magick', [(string) config('whatsapp.media.magick_binary', 'magick'), $sourcePath, '-auto-orient', '-strip', '-quality', $quality, $magickTarget], $magickTarget],
            ['convert', [(string) config('whatsapp.media.convert_binary', 'convert'), $sourcePath, '-auto-orient', '-strip', '-quality', $quality, $convertTarget], $convertTarget],
            ['heif-convert', [(string) config('whatsapp.media.heif_convert_binary', 'heif-convert'), '-q', $quality, $sourcePath, $heifTarget], $heifTarget],
            ['ffmpeg', [(string) config('whatsapp.media.ffmpeg_binary', 'ffmpeg'), '-y', '-i', $sourcePath, '-frames:v', '1', $ffmpegTarget], $ffmpegTarget],
        ];
    }

    private function validConvertedImage(string $path): bool
    {
        return is_file($path) && filesize($path) > 0;
    }

    private function temporaryJpegPath(string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if (! $base) {
            return sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid().'.jpg';
        }

        @unlink($base);

        return $base.'.jpg';
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

        if (str_starts_with($mime, 'audio/')
            || $mime === 'application/ogg'
            || in_array($ext, ['mp3', 'aac', 'm4a', 'amr', 'ogg', 'oga', 'opus', 'weba', 'wav'], true)) {
            return 'audio';
        }

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', '3gp', 'webm'], true)) {
            return 'video';
        }

        return 'document';
    }

    private function normaliseMimeType(string $mimeType, string $extension): string
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        if (in_array($mime, ['', 'application/octet-stream', 'video/mp4'], true)) {
            return match (strtolower($extension)) {
                'm4a' => 'audio/mp4',
                'aac' => 'audio/aac',
                'mp3' => 'audio/mpeg',
                'amr' => 'audio/amr',
                'ogg', 'oga', 'opus' => 'audio/ogg',
                'weba', 'webm' => 'audio/webm',
                'wav' => 'audio/wav',
                default => $mime ?: 'application/octet-stream',
            };
        }

        return $mime;
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
