<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadLimitService
{
    private const MEDIA_MAX_MB = 200;

    private const SOCIAL_IMAGE_MAX_MB = 25;

    private const YOUTUBE_VIDEO_MAX_MB = 500;

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

    public function youtubeVideoMaxKilobytes(): int
    {
        return max(1, (int) floor($this->youtubeVideoMaxBytes() / 1024));
    }

    public function youtubeVideoMaxMegabytes(): int
    {
        return max(1, (int) floor($this->youtubeVideoMaxBytes() / 1024 / 1024));
    }

    public function socialImageMaxKilobytes(): int
    {
        return max(1, (int) floor($this->socialImageMaxBytes() / 1024));
    }

    public function socialImageMaxMegabytes(): int
    {
        return max(1, (int) floor($this->socialImageMaxBytes() / 1024 / 1024));
    }

    private function socialImageMaxBytes(): int
    {
        // Keep the product rule stable across environments. Production upload
        // infrastructure is configured for the larger 500 MB video ceiling.
        return self::SOCIAL_IMAGE_MAX_MB * 1024 * 1024;
    }

    private function youtubeVideoMaxBytes(): int
    {
        // This is Cerqle's application rule. PHP/Nginx are configured above
        // this ceiling in deploy/server so the UI and validator consistently
        // report 500 MB instead of silently inheriting a smaller host default.
        return self::YOUTUBE_VIDEO_MAX_MB * 1024 * 1024;
    }
}
