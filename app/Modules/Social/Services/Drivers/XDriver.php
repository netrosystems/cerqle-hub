<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\XProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class XDriver implements SocialNetworkInterface
{
    public function network(): string
    {
        return 'twitter';
    }

    /** @return array<string, mixed> */
    public function fetchAccountInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->withoutRedirecting()->timeout(30)
                ->get('https://api.x.com/2/users/me', ['user.fields' => 'profile_image_url,username']);
        } catch (ConnectionException) {
            throw new XProviderException('X profile connection failed.', 'transient', 60);
        }
        if (! $response->successful()) {
            throw XProviderException::fromResponse($response);
        }
        $id = $response->json('data.id');
        if (! is_string($id) || $id === '') {
            throw new XProviderException('X profile returned no account ID.', 'provider');
        }

        return ['account_id' => $id, 'name' => $response->json('data.name') ?? $response->json('data.username'), 'username' => $response->json('data.username'), 'picture_url' => $response->json('data.profile_image_url')];
    }

    /** @param array<string, mixed> $postData */
    public function publish(SocialAccount $account, array $postData): string
    {
        $payload = ['text' => (string) ($postData['body'] ?? $postData['text'] ?? '')];
        $ids = array_values($postData['x_media_ids'] ?? []);
        if ($ids !== []) {
            foreach ($ids as $id) {
                if (! is_string($id) || ! preg_match('/^[0-9]{1,19}$/', $id)) {
                    throw new XProviderException('Invalid X media ID.', 'media');
                }
            }
            $payload['media'] = ['media_ids' => $ids];
        } elseif (! empty($postData['media_urls']) || ! empty($postData['media_ids'])) {
            throw new XProviderException('X media must be uploaded before publishing.', 'media');
        }
        try {
            // A create is never automatically retried: delivery may be ambiguous.
            $response = Http::withToken($account->access_token)->acceptJson()->withoutRedirecting()->timeout(30)
                ->post('https://api.x.com/2/tweets', $payload);
        } catch (ConnectionException) {
            throw new XProviderException('X publish connection failed; delivery requires review.', 'unknown', null, 'unknown');
        }
        if (! $response->successful()) {
            throw XProviderException::fromResponse($response, true);
        }
        $id = $response->json('data.id');
        if (! is_string($id) || $id === '') {
            throw new XProviderException('X returned no post ID; delivery requires review.', 'unknown', null, 'unknown');
        }

        return $id;
    }
}
