<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class LinkedInDriver implements SocialNetworkInterface
{
    public function network(): string
    {
        return 'linkedin';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        // The OAuth flow requests OpenID Connect scopes, so identity must be read
        // from the OIDC UserInfo endpoint rather than the retired legacy /v2/me
        // member-profile response shape.
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://api.linkedin.com/v2/userinfo');

        if (! $response->successful()) {
            throw new \RuntimeException('LinkedIn profile lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();

        return [
            'account_id' => $res['sub'] ?? '',
            'name' => $res['name'] ?? trim(($res['given_name'] ?? '').' '.($res['family_name'] ?? '')),
            'picture_url' => $res['picture'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $urn = "urn:li:person:{$account->account_id}";
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? []));
        $options = (array) ($postData['linkedin_options'] ?? []);
        $shareMediaCategory = 'NONE';
        $media = [];

        if (count($mediaUrls) > 1) {
            throw new \RuntimeException('LinkedIn supports one media item per Cerqle post.');
        }
        if ($mediaUrls !== []) {
            [$asset, $kind] = $this->uploadAsset($account, $urn, $mediaUrls[0]);
            $shareMediaCategory = $kind;
            $media[] = [
                'status' => 'READY',
                'description' => ['text' => (string) ($postData['body'] ?? '')],
                'media' => $asset,
                'title' => ['text' => (string) ($postData['title'] ?? 'Cerqle post')],
            ];
        } elseif (! empty($options['link_url'])) {
            $shareMediaCategory = 'ARTICLE';
            $media[] = [
                'status' => 'READY',
                'originalUrl' => $options['link_url'],
                'title' => ['text' => (string) ($postData['title'] ?? $options['link_url'])],
            ];
        }

        $response = Http::withToken($account->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post('https://api.linkedin.com/v2/ugcPosts', [
                'author' => $urn,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => ['text' => $postData['body'] ?? ''],
                        'shareMediaCategory' => $shareMediaCategory,
                        'media' => $media,
                    ],
                ],
                'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('LinkedIn publish failed (HTTP '.$response->status().'): '.$response->body());
        }

        $id = $response->header('X-RestLi-Id') ?: $response->json('id');

        return is_string($id) && $id !== ''
            ? $id
            : throw new \RuntimeException('LinkedIn publish succeeded but returned no post ID.');
    }

    /** @return array{0:string,1:'IMAGE'|'VIDEO'} */
    private function uploadAsset(SocialAccount $account, string $ownerUrn, string $mediaUrl): array
    {
        $path = $this->downloadMedia($mediaUrl);
        try {
            $mime = mime_content_type($path) ?: 'application/octet-stream';
            $kind = str_starts_with($mime, 'video/') ? 'VIDEO' : 'IMAGE';
            if (! str_starts_with($mime, 'video/') && ! str_starts_with($mime, 'image/')) {
                throw new \RuntimeException('LinkedIn media must be an image or video.');
            }

            $register = Http::withToken($account->access_token)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->timeout(30)
                ->post('https://api.linkedin.com/v2/assets?action=registerUpload', [
                    'registerUploadRequest' => [
                        'recipes' => ['urn:li:digitalmediaRecipe:feedshare-'.strtolower($kind)],
                        'owner' => $ownerUrn,
                        'serviceRelationships' => [[
                            'relationshipType' => 'OWNER',
                            'identifier' => 'urn:li:userGeneratedContent',
                        ]],
                    ],
                ]);
            if (! $register->successful()) {
                throw new \RuntimeException('LinkedIn media registration failed (HTTP '.$register->status().').');
            }

            $uploadUrl = data_get($register->json(), 'value.uploadMechanism.com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest.uploadUrl');
            $asset = data_get($register->json(), 'value.asset');
            if (! is_string($uploadUrl) || ! is_string($asset)) {
                throw new \RuntimeException('LinkedIn media registration returned an incomplete upload target.');
            }

            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('LinkedIn media file could not be opened.');
            }
            try {
                $upload = Http::withToken($account->access_token)
                    ->timeout(1200)
                    ->withBody($stream, $mime)
                    ->put($uploadUrl);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $upload->successful()) {
                throw new \RuntimeException('LinkedIn media upload failed (HTTP '.$upload->status().').');
            }

            $this->waitForAsset($account, $asset);

            return [$asset, $kind];
        } finally {
            @unlink($path);
        }
    }

    private function downloadMedia(string $url): string
    {
        $parsed = parse_url($url);
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if (($parsed['scheme'] ?? '') !== 'https' || $host === '' || $host === 'localhost') {
            throw new \RuntimeException('LinkedIn media URL must be a public HTTPS URL.');
        }
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \RuntimeException('LinkedIn media URL resolves to a disallowed network address.');
        }

        $path = tempnam(sys_get_temp_dir(), 'li_');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary LinkedIn media file.');
        }
        $response = Http::withoutRedirecting()->timeout(1200)->sink($path)->get($url);
        if (! $response->successful() || filesize($path) > 500 * 1024 * 1024) {
            @unlink($path);
            throw new \RuntimeException('LinkedIn media download failed or exceeded 500 MB.');
        }

        return $path;
    }

    private function waitForAsset(SocialAccount $account, string $asset): void
    {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            sleep(5);
            $response = Http::withToken($account->access_token)
                ->timeout(20)
                ->get('https://api.linkedin.com/v2/assets/'.rawurlencode($asset));
            $status = (string) ($response->json('status') ?? '');
            if ($status === 'ALLOWED' || $status === 'AVAILABLE') {
                return;
            }
            if (in_array($status, ['CLIENT_ERROR', 'SERVER_ERROR'], true)) {
                throw new \RuntimeException('LinkedIn could not process the uploaded media.');
            }
        }

        throw new \RuntimeException('LinkedIn media processing did not complete in time.');
    }
}
