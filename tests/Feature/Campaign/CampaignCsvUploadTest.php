<?php

namespace Tests\Feature\Campaign;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Jobs\PrepareSmsCampaignAudienceJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Services\CampaignStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CampaignCsvUploadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_real_csv_upload_is_validated_stored_and_attached_to_the_draft(): void
    {
        Storage::fake('local');
        [$user, $workspace, $campaign] = $this->context();
        $file = UploadedFile::fake()->createWithContent('audience.csv', implode("\n", [
            'Phone Number,first_name,opt_in_sms',
            '+8801712345678,Rahim,true',
            '+8801812345678,Ada,false',
            'not-a-number,Bad,true',
        ]));

        $response = $this->actingAs($user)
            ->post(route('client.campaigns.audience-csv', $campaign), ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('name', 'audience.csv')
            ->assertJsonPath('rows', 3)
            ->assertJsonPath('eligible', 1)
            ->assertJsonPath('skipped', 2)
            ->assertJsonPath('ignored_over_limit', 0);

        $campaign->refresh();
        $this->assertSame('csv', $campaign->audience_type);
        $this->assertSame(1, $campaign->estimated_recipients);
        $this->assertStringStartsWith('campaign-imports/'.$workspace->id.'/', $campaign->audience_ref);
        Storage::disk('local')->assertExists($campaign->audience_ref);
    }

    #[Test]
    public function campaign_csv_uses_the_contact_list_row_limit_during_upload_and_preparation(): void
    {
        Storage::fake('local');
        Queue::fake();
        Config::set('contact_imports.max_rows_per_file', 2);
        Config::set('broadcasting.sms.audience_chunk_size', 100);
        [$user, $workspace, $campaign] = $this->context();
        $file = UploadedFile::fake()->createWithContent('limited.csv', implode("\n", [
            'phone_e164,first_name',
            '+8801712345678,One',
            '+8801812345678,Two',
            '+8801912345678,Ignored',
        ]));

        $this->actingAs($user)
            ->post(route('client.campaigns.audience-csv', $campaign), ['file' => $file])
            ->assertOk()
            ->assertJsonPath('eligible', 2)
            ->assertJsonPath('ignored_over_limit', 1)
            ->assertJsonPath('max_rows', 2);

        $campaign->refresh()->update(['status' => 'preparing']);
        app(CampaignStepService::class)->ensure($campaign);
        app()->call([new PrepareSmsCampaignAudienceJob($campaign->id), 'handle']);
        app()->call([new PrepareSmsCampaignAudienceJob($campaign->id), 'handle']);

        $campaign->refresh();
        $this->assertSame(2, $campaign->prepared_recipients);
        $this->assertDatabaseMissing('contacts', [
            'workspace_id' => $workspace->id,
            'phone_e164' => '+8801912345678',
        ]);
    }

    #[Test]
    public function csv_upload_rejects_invalid_headers_and_cross_workspace_campaigns(): void
    {
        Storage::fake('local');
        [$user, , $campaign] = $this->context();
        $invalid = UploadedFile::fake()->createWithContent('invalid.csv', "email,name\nada@example.com,Ada\n");

        $this->actingAs($user)
            ->postJson(route('client.campaigns.audience-csv', $campaign), ['file' => $invalid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $otherUser = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        Workspace::factory()->create(['owner_id' => $otherUser->id]);
        $valid = UploadedFile::fake()->createWithContent('valid.csv', "phone_e164\n+8801712345678\n");

        $this->actingAs($otherUser)
            ->post(route('client.campaigns.audience-csv', $campaign), ['file' => $valid])
            ->assertForbidden();
    }

    #[Test]
    public function campaign_csv_uses_the_contact_list_file_size_limit(): void
    {
        Storage::fake('local');
        Config::set('contact_imports.max_file_mb', 1);
        [$user, , $campaign] = $this->context();
        $file = UploadedFile::fake()->create('too-large.csv', 1025, 'text/csv');

        $this->actingAs($user)
            ->postJson(route('client.campaigns.audience-csv', $campaign), ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertNull($campaign->fresh()->audience_ref);
        Storage::disk('local')->assertDirectoryEmpty('campaign-imports');
    }

    /** @return array{User, Workspace, Campaign} */
    private function context(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'draft',
            'audience_type' => 'contact_list',
        ]);

        return [$user, $workspace, $campaign];
    }
}
