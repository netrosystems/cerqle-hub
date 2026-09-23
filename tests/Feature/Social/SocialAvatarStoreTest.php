<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Jobs\CacheSocialAvatarJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\SocialAvatarStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SocialAvatarStoreTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG, so the image check has something genuine to inspect. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    // Meta's real link shape: signed, with an oe= expiry a few days out.
    private const META_LINK = 'https://scontent-fra3-1.xx.fbcdn.net/v/t39.30808-1/1_n.jpg?stp=c0&oe=68CF0A3B&oh=00_abc';

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
        $this->ctx = $this->createSubscribedWorkspaceContext();
    }

    private function account(array $overrides = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'network' => 'facebook',
            'account_id' => '1001',
            'name' => 'Cerqle.ai',
            'picture_url' => self::META_LINK,
            'access_token' => 'page-token',
        ], $overrides));
    }

    public function test_connecting_an_account_queues_a_copy_of_its_picture(): void
    {
        // The link is only good for days, so the copy is made at connect time.
        Queue::fake();

        $account = $this->account();

        Queue::assertPushed(CacheSocialAvatarJob::class, fn ($job) => $job->accountId === $account->id);
    }

    public function test_the_stored_copy_is_served_instead_of_the_provider_link(): void
    {
        Queue::fake();
        Http::fake(['scontent-fra3-1.xx.fbcdn.net/*' => Http::response(base64_decode(self::PNG), 200)]);
        $account = $this->account();

        $this->assertTrue(app(SocialAvatarStore::class)->store($account));
        $account->refresh();

        $this->assertNotNull($account->picture_path);
        Storage::disk('public')->assertExists($account->picture_path);
        // The page now reads our copy, which never expires, not Meta's link.
        $this->assertStringNotContainsString('fbcdn.net', $account->picture_url);
        $this->assertStringContainsString('social-avatars/', $account->picture_url);
        // The provider link is kept as the source it came from.
        $this->assertSame(self::META_LINK, $account->providerPictureUrl());
    }

    public function test_storing_the_copy_does_not_queue_another_copy(): void
    {
        // Saving the stored path must not read as "a new picture link arrived",
        // or every copy would schedule a copy of itself.
        Http::fake(['scontent-fra3-1.xx.fbcdn.net/*' => Http::response(base64_decode(self::PNG), 200)]);
        Queue::fake();
        $account = $this->account();
        Queue::assertPushed(CacheSocialAvatarJob::class, 1);

        app(SocialAvatarStore::class)->store($account);

        Queue::assertPushed(CacheSocialAvatarJob::class, 1);
    }

    public function test_an_expired_link_leaves_the_account_untouched(): void
    {
        // Exactly the state of accounts connected before this fix: Meta has
        // already withdrawn the image. Nothing must be stored or changed.
        Queue::fake();
        Http::fake(['scontent-fra3-1.xx.fbcdn.net/*' => Http::response('URL signature expired', 403)]);
        $account = $this->account();

        $this->assertFalse(app(SocialAvatarStore::class)->store($account));

        $this->assertNull($account->fresh()->picture_path);
        $this->assertSame(self::META_LINK, $account->fresh()->picture_url);
    }

    public function test_something_that_is_not_an_image_is_refused(): void
    {
        Queue::fake();
        Http::fake(['scontent-fra3-1.xx.fbcdn.net/*' => Http::response('<html>login wall</html>', 200)]);
        $account = $this->account();

        $this->assertFalse(app(SocialAvatarStore::class)->store($account));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_link_to_a_private_address_is_never_fetched(): void
    {
        // The link comes from a provider response, but it is fetched from the
        // server, so it gets the same checks as any outbound URL.
        Queue::fake();
        Http::fake();
        $account = $this->account(['picture_url' => 'https://127.0.0.1/avatar.png']);

        $this->assertFalse(app(SocialAvatarStore::class)->store($account));
        Http::assertNothingSent();
    }

    public function test_a_broken_facebook_picture_is_repaired_from_a_fresh_link(): void
    {
        // The repair path for accounts whose stored link has already died:
        // ask Meta for a current one with the page's own token, then store it.
        Queue::fake();
        $fresh = 'https://scontent-fra3-1.xx.fbcdn.net/v/fresh.jpg?oe=6FFFFFFF';
        Http::fake([
            'graph.facebook.com/*/1001/picture*' => Http::response(['data' => ['url' => $fresh]]),
            'scontent-fra3-1.xx.fbcdn.net/v/fresh.jpg*' => Http::response(base64_decode(self::PNG), 200),
            'scontent-fra3-1.xx.fbcdn.net/*' => Http::response('URL signature expired', 403),
        ]);
        $account = $this->account();
        $store = app(SocialAvatarStore::class);

        $this->assertSame($fresh, $store->freshPictureUrl($account));
        $this->assertTrue($store->store($account, $fresh));
        $this->assertNotNull($account->fresh()->picture_path);
    }
}
