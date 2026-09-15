<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\File;

final class SafeUploadName
{
    public static function extension(UploadedFile $file): string
    {
        $extension = strtolower((new File($file->getPathname()))->guessExtension() ?? 'bin');
        // The client filename is display-only, never a storage extension.
        if (! preg_match('/^[a-z0-9]{1,10}$/', $extension)
            || in_array($extension, ['html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'js', 'mjs', 'php', 'phtml', 'phar', 'shtml', 'htaccess'], true)) {
            return 'bin';
        }

        return $extension;
    }
}
