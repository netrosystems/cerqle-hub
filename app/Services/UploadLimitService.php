<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadLimitService
{
    private const MEDIA_MAX_MB = 50;

    public function mediaMaxBytes(): int
    {
        $applicationLimit = self::MEDIA_MAX_MB * 1024 * 1024;
        $phpLimit = UploadedFile::getMaxFilesize();

        if (! is_numeric($phpLimit) || $phpLimit <= 0) {
            return $applicationLimit;
        }

        return (int) min($applicationLimit, $phpLimit);
    }

    public function mediaMaxKilobytes(): int
    {
        return max(1, (int) floor($this->mediaMaxBytes() / 1024));
    }

    public function mediaMaxMegabytes(): int
    {
        return max(1, (int) floor($this->mediaMaxBytes() / 1024 / 1024));
    }
}
