<?php

namespace Tests\Feature;

use App\Modules\Shared\Jobs\AddContactsToListJob;
use App\Modules\Shared\Jobs\ImportContactsToListJob;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactListOperation;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactListBulkManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_contacts_are_scoped_to_the_current_workspace(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Customers', 'type' => 'static']);
        $own = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550101']);
        $other = Contact::create(['workspace_id' => $otherWorkspace->id, 'phone_e164' => '+12025550102']);

        $this->actingAs($user)->post(route('client.segments.contacts.attach', $list), [
            'selection' => 'selected',
            'contact_ids' => [$own->id, $other->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('segment_contact', ['segment_id' => $list->id, 'contact_id' => $own->id]);
        $this->assertDatabaseMissing('segment_contact', ['segment_id' => $list->id, 'contact_id' => $other->id]);
    }

    public function test_select_all_queues_a_snapshot_and_adds_every_matching_contact(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Leads', 'type' => 'static']);
        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550111', 'first_name' => 'Target']);
        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550112', 'first_name' => 'Target']);
        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550113', 'first_name' => 'Ignore']);

        $this->actingAs($user)->post(route('client.segments.contacts.attach', $list), [
            'selection' => 'all',
            'search' => 'Target',
        ])->assertRedirect();

        $operation = ContactListOperation::firstOrFail();
        $this->assertSame(2, $operation->total);
        Queue::assertPushed(AddContactsToListJob::class);

        (new AddContactsToListJob($operation->id))->handle();
        $this->assertSame(2, $list->contacts()->count());
        $this->assertSame('completed', $operation->fresh()->status);
    }

    public function test_csv_import_is_queued_and_streamed_into_the_contact_list(): void
    {
        Queue::fake();
        Storage::fake('local');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'CSV list', 'type' => 'static']);
        $csv = "phone_e164,first_name,email,opt_in_sms\n0096170111111,Ada,ada@example.test,true\n96170222222,Grace,grace@example.test,true\n70123456,Skip,,true\n";

        $this->actingAs($user)->post(route('client.segments.contacts.import', $list), [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ])->assertRedirect();

        $operation = ContactListOperation::firstOrFail();
        Queue::assertPushed(ImportContactsToListJob::class);
        (new ImportContactsToListJob($operation->id))->handle();

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->campaignOnly()->count());
        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->customerDirectory()->count());
        $this->assertSame(2, $list->contacts()->count());
        $this->assertSame(1, $operation->fresh()->skipped);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170111111']);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170222222']);
        Storage::disk('local')->assertMissing($operation->source_path);
    }

    public function test_client_can_remove_all_existing_contacts_from_a_contact_list_without_deleting_them(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Customers', 'type' => 'static']);
        $first = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550101']);
        $second = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550102']);
        $uploaded = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+96170999999', 'is_campaign_only' => true]);
        $list->contacts()->sync([$first->id, $second->id, $uploaded->id]);

        $this->actingAs($user)->delete(route('client.segments.contacts.detach-all', $list))->assertRedirect();

        $this->assertSame(1, $list->fresh()->contacts()->count());
        $this->assertTrue($list->fresh()->contacts()->whereKey($uploaded->id)->exists());
        $this->assertDatabaseHas('contacts', ['id' => $first->id]);
        $this->assertDatabaseHas('contacts', ['id' => $second->id]);
    }

    public function test_client_can_download_the_contact_list_csv_sample(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)->get(route('client.segments.contacts.sample-csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_real_customer_activity_promotes_an_uploaded_recipient_into_the_directory(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $service = app(ContactService::class);
        $uploaded = $service->upsert($workspace->id, [
            'phone_e164' => '+96170333333',
            'first_name' => 'Campaign',
            'source' => 'contact_list_csv',
        ], false);

        $this->assertTrue($uploaded->is_campaign_only);

        $customer = $service->upsert($workspace->id, [
            'phone_e164' => '+96170333333',
            'source' => 'whatsapp_inbound',
            'opt_in_whatsapp' => true,
        ]);

        $this->assertSame($uploaded->id, $customer->id);
        $this->assertFalse($customer->fresh()->is_campaign_only);
        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->customerDirectory()->count());
    }

    public function test_campaign_upload_never_demotes_an_existing_customer(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $service = app(ContactService::class);
        $customer = $service->upsert($workspace->id, [
            'phone_e164' => '+96170444444',
            'first_name' => 'Real',
            'source' => 'manual',
        ]);

        $service->upsert($workspace->id, [
            'phone_e164' => '+96170444444',
            'first_name' => 'Campaign copy',
            'source' => 'campaign_csv',
        ], false);

        $this->assertFalse($customer->fresh()->is_campaign_only);
        $this->assertSame('Real', $customer->fresh()->first_name);
        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->customerDirectory()->count());
    }
}
