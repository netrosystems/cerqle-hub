<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;

interface ChecksPublishProcessing
{
    /**
     * @return array{status:'processing'|'published'|'failed', url?:string, error?:string}
     */
    public function checkPublishProcessing(SocialAccount $account, string $platformPostId): array;
}
