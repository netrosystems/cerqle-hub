<?php

namespace Tests\Feature\Campaign;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CampaignCloneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function any_campaign_can_be_cloned_as_a_clean_draft(): void
    {
        [$user, $workspace] = $this->context();

        $source = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'September WhatsApp campaign',
            'channel' => 'whatsapp',
            'whatsapp_waba_id' => 'waba-1',
            'whatsapp_phone_number_id' => 'phone-1',
            'audience_type' => 'segment',
            'audience_ref' => '42',
            'template_ref' => ['name' => 'sale', 'language' => 'en'],
            'payload_json' => ['body' => 'Hello {{first_name}}'],
            'schedule_at' => now()->addDay(),
            'timezone' => 'Asia/Dhaka',
            'status' => 'completed_with_failures',
            'totals_json' => ['total' => 1, 'failed' => 1],
            'provider_key' => 'meta:phone-1',
            'estimated_recipients' => 1,
            'prepared_recipients' => 1,
            'preparation_cursor' => 99,
            'preparation_offset' => 1,
            'audience_cutoff_id' => 99,
            'is_large' => true,
            'pause_reason' => 'Old failure',
            'audience_prepared_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $source->steps()->create([
            'position' => 1,
            'name' => 'Safety check',
            'recipient_limit' => null,
            'delay_after_previous_seconds' => 30,
            'rate_per_second' => 2,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        CampaignRecipient::create([
            'campaign_id' => $source->id,
            'campaign_step_id' => $source->steps()->first()->id,
            'contact_id' => $contact->id,
            'status' => 'failed',
            'failed_reason' => 'Old delivery failure',
        ]);

        $response = $this->actingAs($user)
            ->post(route('client.campaigns.clone', $source));

        $copy = Campaign::where('workspace_id', $workspace->id)
            ->where('id', '!=', $source->id)
            ->sole();
        $response->assertRedirect(route('client.campaigns.edit', $copy));
        $response->assertSessionHas('success');

        $this->assertSame('Copy of September WhatsApp campaign', $copy->name);
        $this->assertSame('draft', $copy->status);
        $this->assertSame($user->id, $copy->created_by);
        $this->assertSame($source->channel, $copy->channel);
        $this->assertSame($source->whatsapp_waba_id, $copy->whatsapp_waba_id);
        $this->assertSame($source->whatsapp_phone_number_id, $copy->whatsapp_phone_number_id);
        $this->assertSame($source->audience_type, $copy->audience_type);
        $this->assertSame($source->audience_ref, $copy->audience_ref);
        $this->assertSame($source->template_ref, $copy->template_ref);
        $this->assertSame($source->payload_json, $copy->payload_json);
        $this->assertSame($source->timezone, $copy->timezone);

        $this->assertNull($copy->schedule_at);
        $this->assertNull($copy->totals_json);
        $this->assertNull($copy->provider_key);
        $this->assertSame(0, $copy->estimated_recipients);
        $this->assertSame(0, $copy->prepared_recipients);
        $this->assertSame(0, $copy->preparation_cursor);
        $this->assertSame(0, $copy->preparation_offset);
        $this->assertNull($copy->audience_cutoff_id);
        $this->assertFalse($copy->is_large);
        $this->assertNull($copy->pause_reason);
        $this->assertNull($copy->audience_prepared_at);
        $this->assertNull($copy->started_at);
        $this->assertNull($copy->completed_at);
        $this->assertCount(0, $copy->recipients);

        $step = $copy->steps()->sole();
        $this->assertSame('Safety check', $step->name);
        $this->assertSame(30, $step->delay_after_previous_seconds);
        $this->assertSame(2, $step->rate_per_second);
        $this->assertSame('pending', $step->status);
        $this->assertNull($step->started_at);
        $this->assertNull($step->completed_at);
    }

    #[Test]
    public function csv_campaigns_receive_an_independent_file_copy(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->context();
        $sourcePath = 'campaign-imports/'.$workspace->id.'/source.csv';
        Storage::disk('local')->put($sourcePath, "phone_e164\n+8801700000000\n");

        $source = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'audience_type' => 'csv',
            'audience_ref' => $sourcePath,
            'estimated_recipients' => 1,
            'status' => 'failed',
        ]);

        $this->actingAs($user)
            ->post(route('client.campaigns.clone', $source))
            ->assertRedirect();

        $copy = Campaign::where('id', '!=', $source->id)->sole();
        $this->assertNotSame($sourcePath, $copy->audience_ref);
        $this->assertSame(1, $copy->estimated_recipients);
        Storage::disk('local')->assertExists($sourcePath);
        Storage::disk('local')->assertExists($copy->audience_ref);

        $this->actingAs($user)->delete(route('client.campaigns.destroy', $source));

        Storage::disk('local')->assertMissing($sourcePath);
        Storage::disk('local')->assertExists($copy->audience_ref);
    }

    #[Test]
    public function a_campaign_from_another_workspace_cannot_be_cloned(): void
    {
        [$user, $workspace] = $this->context();
        $other = Workspace::factory()->create();
        $source = Campaign::factory()->create([
            'workspace_id' => $other->id,
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->post(route('client.campaigns.clone', $source))
            ->assertForbidden();

        $this->assertSame(0, Campaign::where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Campaign::where('workspace_id', $other->id)->count());
    }

    #[Test]
    public function a_campaign_with_a_missing_csv_still_clones_and_requests_a_replacement(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->context();
        $source = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'audience_type' => 'csv',
            'audience_ref' => 'campaign-imports/'.$workspace->id.'/missing.csv',
            'estimated_recipients' => 50,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->post(route('client.campaigns.clone', $source));

        $copy = Campaign::where('id', '!=', $source->id)->sole();
        $response->assertRedirect(route('client.campaigns.edit', $copy));
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, 'upload a new CSV'));
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->audience_ref);
        $this->assertSame(0, $copy->estimated_recipients);
    }

    private function context(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return [$user->fresh(), $workspace];
    }
}
