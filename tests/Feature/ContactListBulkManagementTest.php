<?php

namespace Tests\Feature;

use App\Modules\Shared\Jobs\AddContactsToListJob;
use App\Modules\Shared\Jobs\ImportContactsToListJob;
use App\Modules\Shared\Jobs\ValidateContactListCsvJob;
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
        Queue::assertPushed(ValidateContactListCsvJob::class);
        (new ValidateContactListCsvJob($operation->id))->handle();
        $operation->refresh();

        // Validation must be non-mutating: clients review this result before
        // their uploaded recipients become campaign contacts.
        $this->assertSame('completed', $operation->status);
        $this->assertSame('csv_validation', $operation->type);
        $this->assertSame(2, $operation->added);
        $this->assertSame(1, $operation->skipped);
        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());

        $this->actingAs($user)->post(route('client.segments.contacts.import.confirm', [$list, $operation]))
            ->assertRedirect();
        Queue::assertPushed(ImportContactsToListJob::class);
        $import = ContactListOperation::where('type', 'csv_import')->firstOrFail();
        (new ImportContactsToListJob($import->id))->handle();

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->campaignOnly()->count());
        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->customerDirectory()->count());
        $this->assertSame(2, $list->contacts()->count());
        $this->assertSame(1, $import->fresh()->skipped);
        $this->assertSame(0, $import->fresh()->skipped_existing_customer);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170111111']);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170222222']);
        Storage::disk('local')->assertMissing($import->source_path);
    }

    public function test_csv_import_reports_rows_that_match_an_existing_customer_separately(): void
    {
        Queue::fake();
        Storage::fake('local');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'CSV list', 'type' => 'static']);
        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+96170555555', 'first_name' => 'Real']);
        $csv = "phone_e164,first_name,email,opt_in_sms\n0096170555555,Override,over@example.test,true\n96170666666,New,new@example.test,true\n";

        $this->actingAs($user)->post(route('client.segments.contacts.import', $list), [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ])->assertRedirect();

        $operation = ContactListOperation::firstOrFail();
        (new ImportContactsToListJob($operation->id))->handle();
        $operation->refresh();

        // The "Real" customer stays untouched; only the truly new number is
        // attached to the list, but the operation still reports the overlap
        // explicitly so the UI can explain the gap.
        $this->assertSame('Real', Contact::where('phone_e164', '+96170555555')->first()->first_name);
        $this->assertSame(1, $list->contacts()->count());
        $this->assertSame(2, $operation->processed);
        $this->assertSame(1, $operation->added);
        $this->assertSame(1, $operation->skipped_existing_customer);
        $this->assertSame(0, $operation->skipped);
    }

    public function test_manage_contacts_renders_a_merged_list_with_source_tags(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Mixed', 'type' => 'static']);
        $existing = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550199']);
        $uploaded = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+96170999999', 'is_campaign_only' => true]);
        $list->contacts()->sync([$existing->id, $uploaded->id]);
        $list->update(['contact_count' => 2]);

        $response = $this->actingAs($user)->get(route('client.segments.contacts', $list))->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Contacts/SegmentContacts')
            ->where('existingContactsCount', 1)
            ->where('uploadedContactsCount', 1)
            ->where('listContacts.total', 2)
            ->has('listContacts.data', 2)
        );
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

    public function test_client_can_clear_an_entire_contact_list_without_deleting_any_contacts(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'All recipients', 'type' => 'static']);
        $customer = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+12025550111', 'first_name' => 'CRM', 'source' => 'manual']);
        $uploaded = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => '+96170999999', 'is_campaign_only' => true]);
        $list->contacts()->sync([$customer->id, $uploaded->id]);

        $this->actingAs($user)->delete(route('client.segments.contacts.clear', $list))->assertRedirect();

        $list->refresh();
        $this->assertSame(0, $list->contacts()->count());
        $this->assertSame(0, (int) $list->contact_count);
        // No contact record is deleted.
        $this->assertDatabaseHas('contacts', ['id' => $customer->id]);
        $this->assertDatabaseHas('contacts', ['id' => $uploaded->id]);
    }

    public function test_clearing_a_contact_list_is_not_gated_by_a_list_type(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Empty']);

        $this->actingAs($user)->delete(route('client.segments.contacts.clear', $list))->assertRedirect();
    }

    public function test_contact_lists_are_always_created_as_static(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.segments.store'), [
            'name' => 'SMS recipients',
            // Legacy clients may still submit this field; it must have no
            // effect now that dynamic lists are removed.
            'type' => 'dynamic',
        ])->assertRedirect();

        $this->assertDatabaseHas('segments', [
            'workspace_id' => $workspace->id,
            'name' => 'SMS recipients',
            'type' => 'static',
        ]);
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

    public function test_csv_import_accepts_all_real_number_shapes_and_normalises_them_to_e164(): void
    {
        Queue::fake();
        Storage::fake('local');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Normalisation', 'type' => 'static']);
        // All four shapes should normalise to a real E.164 number:
        //   +96170123456           — already E.164
        //   0096170654321          — 00 international prefix
        //   96170765432            — bare international digits
        //   070111222 with country — national number, default country from file
        $csv = "phone_e164,first_name,country\n+96170123456,Ada,LB\n0096170654321,Grace,LB\n96170765432,Marie,LB\n070111222,Jana,LB\n";

        $this->actingAs($user)->post(route('client.segments.contacts.import', $list), [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ])->assertRedirect();

        $operation = ContactListOperation::firstOrFail();
        (new ImportContactsToListJob($operation->id))->handle();
        $operation->refresh();

        $this->assertSame(4, $operation->added);
        $this->assertSame(0, $operation->skipped);
        $this->assertSame(0, $operation->skipped_invalid_phone);
        $this->assertSame(0, $operation->skipped_malformed_row);
        $this->assertSame(0, $operation->skipped_duplicate_in_file);
        $this->assertSame(4, $list->fresh()->contacts()->count());
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170123456', 'first_name' => 'Ada']);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170654321']);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170765432', 'first_name' => 'Marie']);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170111222', 'first_name' => 'Jana']);
    }

    public function test_csv_import_rejects_national_numbers_without_a_country_column(): void
    {
        Queue::fake();
        Storage::fake('local');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Strict', 'type' => 'static']);
        // 070123456 is "national" without a country column — we cannot place
        // it safely, so it must be reported as an invalid phone, not silently
        // dropped.
        $csv = "phone_e164,first_name\n070123456,Local\n0096170123456,Intl\n";

        $this->actingAs($user)->post(route('client.segments.contacts.import', $list), [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ])->assertRedirect();

        $operation = ContactListOperation::firstOrFail();
        (new ImportContactsToListJob($operation->id))->handle();
        $operation->refresh();

        $this->assertSame(1, $operation->added);
        $this->assertSame(1, $operation->skipped_invalid_phone);
        $this->assertSame(0, $operation->skipped_malformed_row);
        $this->assertSame(0, $operation->skipped_duplicate_in_file);
        $this->assertSame(1, $operation->skipped);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+96170123456']);
        $this->assertDatabaseMissing('contacts', ['workspace_id' => $workspace->id, 'phone_e164' => '+9617012345']);
    }

    public function test_csv_import_splits_rejections_into_phone_row_and_duplicate_buckets(): void
    {
        Queue::fake();
        Storage::fake('local');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $list = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Breakdown', 'type' => 'static']);
        // 1 valid, 1 invalid phone, 1 malformed row (wrong column count),
        // 1 in-file duplicate of the valid row.
        $csv = "phone_e164,first_name\n+96170123456,Valid\nnot-a-phone,Bad\n+96170123456,Valid\n+96170888888,Extra,Extra\n";

        $this->actingAs($user)->post(route('client.segments.contacts.import', $list), [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ])->assertRedirect();

        $operation = ContactListOperation::firstOrFail();
        (new ImportContactsToListJob($operation->id))->handle();
        $operation->refresh();

        $this->assertSame(1, $operation->added);
        $this->assertSame(1, $operation->skipped_invalid_phone);
        $this->assertSame(1, $operation->skipped_malformed_row);
        $this->assertSame(1, $operation->skipped_duplicate_in_file);
        $this->assertSame(3, $operation->skipped);
        $this->assertSame(1, $list->fresh()->contacts()->count());
    }
}
