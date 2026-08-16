<?php

namespace App\Modules\Inbox\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ChatWidgetAvatarProcessor
{
    public const SIZE = 160;

    /** Convert an uploaded avatar to a compact, square WebP image. */
    public function process(UploadedFile $file): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages([
                'avatar_image' => 'Avatar processing is unavailable on this server. Enable PHP GD with WebP support.',
            ]);
        }

        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);

        if ($source === false) {
            throw ValidationException::withMessages([
                'avatar_image' => 'The selected file is not a readable image.',
            ]);
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $cropSize = min($sourceWidth, $sourceHeight);
            $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
            $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);

            $avatar = imagecreatetruecolor(self::SIZE, self::SIZE);
            if ($avatar === false) {
                throw ValidationException::withMessages([
                    'avatar_image' => 'The avatar could not be processed.',
                ]);
            }

            try {
                imagealphablending($avatar, false);
                imagesavealpha($avatar, true);
                $transparent = imagecolorallocatealpha($avatar, 0, 0, 0, 127);
                imagefilledrectangle($avatar, 0, 0, self::SIZE, self::SIZE, $transparent);
                imagecopyresampled(
                    $avatar,
                    $source,
                    0,
                    0,
                    $sourceX,
                    $sourceY,
                    self::SIZE,
                    self::SIZE,
                    $cropSize,
                    $cropSize,
                );

                ob_start();
                $encoded = imagewebp($avatar, null, 75);
                $webp = ob_get_clean();

                if (! $encoded || ! is_string($webp) || $webp === '') {
                    throw ValidationException::withMessages([
                        'avatar_image' => 'The avatar could not be compressed.',
                    ]);
                }

                return $webp;
            } finally {
                imagedestroy($avatar);
            }
        } finally {
            imagedestroy($source);
        }
    }
}
