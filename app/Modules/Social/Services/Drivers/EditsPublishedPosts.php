<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;

interface EditsPublishedPosts
{
    /** Update content on a post that has already been published. */
    public function updatePublishedPost(SocialAccount $account, string $platformPostId, array $postData): void;
}
