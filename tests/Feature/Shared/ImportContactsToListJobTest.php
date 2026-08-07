<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Jobs\ImportContactsToListJob;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactListOperation;
use App\Modules\Shared\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins down the consent semantics for CSV uploads into a contact list.
 *
 * The rule: UPLOAD = CONSENT. Only explicit opt-out tokens reject. Anything
 * else — missing column, blank cell, unknown garbage — opts the contact in
 * for SMS, because the operator's act of uploading the list is the consent
 * signal. See ContactService::coerceOptIn() for the canonical list of
 * opt-out tokens (English + Arabic).
 */
class ImportContactsToListJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_without_opt_in_sms_column_opts_everyone_in(): void
    {
        $this->importRows(['+447700900001', '+447700900002']);

        $this->assertOptIn('+447700900001', 1);
        $this->assertOptIn('+447700900002', 1);
    }

    public function test_blank_opt_in_sms_cell_opts_in(): void
    {
        // Blank cells used to coerce to false via FILTER_VALIDATE_BOOL.
        // Upload is the consent signal — blanks should opt in.
        $this->importRows(
            ['+447700900010', '+447700900011'],
            optInValues: ['', '   '],
        );

        $this->assertOptIn('+447700900010', 1);
        $this->assertOptIn('+447700900011', 1);
    }

    public function test_explicit_opt_out_tokens_reject(): void
    {
        // Every value in ContactService::OPT_OUT_TOKENS should opt out.
        $this->importRows(
            phones: [
                '+447700900020', '+447700900021', '+447700900022',
                '+447700900023', '+447700900024', '+447700900025',
                '+447700900026', // Arabic "لا"
            ],
            optInValues: ['no', '0', 'false', 'off', 'unsubscribe', 'optout', 'لا'],
        );

        foreach (
            ['+447700900020', '+447700900021', '+447700900022', '+447700900023',
             '+447700900024', '+447700900025', '+447700900026'] as $phone
        ) {
            $this->assertOptIn($phone, 0, "phone {$phone} should be opted out");
        }
    }

    public function test_arbitrary_unrecognized_strings_opt_in(): void
    {
        // FILTER_VALIDATE_BOOL used to silently turn garbage into false.
        // After the fix, anything that isn't an opt-out token opts in.
        $this->importRows(
            phones: ['+447700900030', '+447700900031', '+447700900032'],
            optInValues: ['maybe', 'abc', 'yes please'], // 'yes' is NOT in OPT_OUT_TOKENS
        );

        foreach (['+447700900030', '+447700900031', '+447700900032'] as $phone) {
            $this->assertOptIn($phone, 1, "phone {$phone} should be opted in");
        }
    }

    public function test_imported_rows_are_marked_campaign_only(): void
    {
        // List-imports stay scoped to the uploaded list by design. We do
        // NOT change is_campaign_only here — that's a separate concern.
        $this->importRows(['+447700900040']);

        $row = Contact::where('phone_e164', '+447700900040')->firstOrFail();
        $this->assertSame(1, (int) $row->is_campaign_only);
        $this->assertSame(1, (int) $row->opt_in_sms);
        $this->assertSame('contact_list_csv', $row->source);
    }

    /**
     * Build a workspace, a static segment, and a CSV on disk; dispatch the
     * job synchronously and return once it has run.
     */
    private function importRows(array $phones, ?array $optInValues = null): void
    {
        $workspaceId = 1;
        $segment = Segment::create([
            'workspace_id' => $workspaceId,
            'name' => 'Test List',
            'type' => 'static',
            'contact_count' => 0,
        ]);

        $relPath = 'imports/test-'.uniqid('', true).'.csv';
        Storage::disk('local')->put($relPath, $this->csvBody($phones, $optInValues));

        $operation = ContactListOperation::create([
            'workspace_id' => $workspaceId,
            'segment_id' => $segment->id,
            'type' => 'csv_import',
            'source_path' => $relPath,
            'status' => 'pending',
            'total' => count($phones),
        ]);

        (new ImportContactsToListJob($operation->id))->handle();
    }

    /**
     * Build a CSV body. If $optInValues is null, the opt_in_sms column is
     * omitted entirely. Otherwise each row gets the matching value (or
     * empty string if there are fewer values than phones — i.e. blank cell).
     */
    private function csvBody(array $phones, ?array $optInValues): string
    {
        $fh = fopen('php://temp', 'r+');

        if ($optInValues === null) {
            fputcsv($fh, ['phone_e164', 'first_name']);
            foreach ($phones as $phone) {
                fputcsv($fh, [$phone, 'Test']);
            }
        } else {
            fputcsv($fh, ['phone_e164', 'first_name', 'opt_in_sms']);
            foreach ($phones as $i => $phone) {
                $optIn = $optInValues[$i] ?? '';
                fputcsv($fh, [$phone, 'Test', $optIn]);
            }
        }

        rewind($fh);
        $body = stream_get_contents($fh);
        fclose($fh);

        return (string) $body;
    }

    private function assertOptIn(string $phone, int $expected, string $message = ''): void
    {
        $row = Contact::where('phone_e164', $phone)->first();
        $this->assertNotNull($row, "contact {$phone} should have been imported");
        $this->assertSame(
            $expected,
            (int) $row->opt_in_sms,
            $message ?: "phone {$phone} opt_in_sms should be {$expected}",
        );
    }
}