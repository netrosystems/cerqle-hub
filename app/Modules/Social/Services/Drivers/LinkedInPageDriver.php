<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

/**
 * Publishes to a LinkedIn company page.
 *
 * The difference from {@see LinkedInDriver} is one field — the author URN is
 * an organisation rather than a person — but it needs its own token, granted
 * through the Community Management API, so it is a separate driver rather than
 * a flag on the member one.
 *
 * One authorisation can cover several pages, the way a Meta login covers
 * several Facebook Pages, so the callback asks {@see organizations()} which
 * ones this person administers and stores each as its own account.
 */
class LinkedInPageDriver implements SocialNetworkInterface
{
    public function network(): string
    {
        return 'linkedin_page';
    }

    /**
     * A page connection has no single identity to return: the authorising
     * member may administer none, one or many. The controller calls
     * {@see organizations()} instead and creates an account per page.
     */
    public function fetchAccountInfo(string $accessToken): array
    {
        return ['account_id' => '', 'name' => '', 'picture_url' => null];
    }

    /**
     * The company pages the token holder administers.
     *
     * @return list<array{account_id:string,name:string,picture_url:?string}>
     */
    public function organizations(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->acceptJson()
            ->timeout(30)
            ->get('https://api.linkedin.com/v2/organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'state' => 'APPROVED',
                'projection' => '(elements*(organization~(id,localizedName,logoV2(original~:playableStreams))))',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('LinkedIn company page lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $pages = [];
        foreach ((array) $response->json('elements', []) as $element) {
            // The `organization~` key is LinkedIn's decorated projection; the
            // plain `organization` key beside it is only the URN string.
            $organization = $element['organization~'] ?? null;
            $urn = (string) ($element['organization'] ?? '');
            $id = (string) ($organization['id'] ?? $this->idFromUrn($urn));
            if ($id === '') {
                continue;
            }

            $pages[] = [
                'account_id' => $id,
                'name' => (string) ($organization['localizedName'] ?? 'LinkedIn page '.$id),
                'picture_url' => $this->logoUrl($organization),
            ];
        }

        return $pages;
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $urn = "urn:li:organization:{$account->account_id}";
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? []));
        $options = (array) ($postData['linkedin_options'] ?? []);
        $shareMediaCategory = 'NONE';
        $media = [];

        if (count($mediaUrls) > 1) {
            throw new \RuntimeException('LinkedIn supports one media item per Cerqle post.');
        }
        if ($mediaUrls !== []) {
            [$asset, $kind] = app(LinkedInDriver::class)->uploadAssetFor($account, $urn, $mediaUrls[0]);
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
            throw new \RuntimeException('LinkedIn page publish failed (HTTP '.$response->status().'): '.$response->body());
        }

        $id = $response->header('X-RestLi-Id') ?: $response->json('id');

        return is_string($id) && $id !== ''
            ? $id
            : throw new \RuntimeException('LinkedIn page publish succeeded but returned no post ID.');
    }

    /** `urn:li:organization:1234` → `1234`. */
    private function idFromUrn(string $urn): string
    {
        $parts = explode(':', $urn);

        return (string) (end($parts) ?: '');
    }

    /** @param array<string, mixed>|null $organization */
    private function logoUrl(?array $organization): ?string
    {
        $streams = data_get($organization, 'logoV2.original~.elements', []);
        $url = data_get($streams, '0.identifiers.0.identifier');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
