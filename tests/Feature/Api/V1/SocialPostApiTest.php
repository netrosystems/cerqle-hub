<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialPostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_can_queue_a_complete_youtube_upload(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('youtube-test', [ApiAbilities::SOCIAL_WRITE])->plainTextToken;
        $account = $this->youtubeAccount($workspace->id);

        $response = $this->withToken($token)->postJson('/api/v1/social/posts', [
            'title' => 'API upload',
            'body' => '',
            'media_urls' => ['https://cdn.example.com/videos/api-upload.mp4'],
            'account_ids' => [$account->id],
            'youtube_options' => [
                'privacy_status' => 'private',
                'category_id' => 27,
                'tags' => ['cerqle', 'api'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('status', 'publishing');
        $post = SocialPost::findOrFail($response->json('id'));
        $this->assertSame('private', $post->youtube_options['privacy_status']);
        Queue::assertPushed(PublishSocialPostJob::class);
    }

    public function test_api_rejects_a_youtube_watch_page_before_queueing(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('youtube-test', [ApiAbilities::SOCIAL_WRITE])->plainTextToken;
        $account = $this->youtubeAccount($workspace->id);

        $this->withToken($token)->postJson('/api/v1/social/posts', [
            'title' => 'Wrong URL',
            'body' => '',
            'media_urls' => ['https://youtu.be/example'],
            'account_ids' => [$account->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['media_urls.0']);

        $this->assertDatabaseCount('social_media_posts', 0);
        Queue::assertNothingPushed();
    }

    private function youtubeAccount(int $workspaceId): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'youtube',
            'account_id' => 'api-channel',
            'name' => 'API Channel',
            'access_token' => 'token',
            'active' => true,
        ]);
    }
}
