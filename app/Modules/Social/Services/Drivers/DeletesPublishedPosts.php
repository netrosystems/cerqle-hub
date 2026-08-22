<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;

interface DeletesPublishedPosts
{
    /** Delete a post that has already been published. */
    public function deletePublishedPost(SocialAccount $account, string $platformPostId): void;
}
