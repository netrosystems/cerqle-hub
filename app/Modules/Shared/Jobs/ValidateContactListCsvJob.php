<?php

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactListOperation;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Scans an uploaded list before it can become campaign audience data. This is
 * intentionally non-mutating: the user can review a clear result before any
 * recipient is created or attached to a Contact List.
 */
class ValidateContactListCsvJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 2;

    public function __construct(public int $operationId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $operation = ContactListOperation::findOrFail($this->operationId);
        Segment::whereKey($operation->segment_id)
            ->where('workspace_id', $operation->workspace_id)
            ->where('type', 'static')
            ->firstOrFail();

        $operation->update(['status' => 'processing', 'started_at' => now(), 'error_message' => null]);
        $path = Storage::disk('local')->path((string) $operation->source_path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The uploaded CSV could not be opened.');
        }

        $normaliser = new ImportContactsToListJob($operation->id);
        $headers = $normaliser->normaliseHeaders(fgetcsv($handle) ?: []);
        if (! in_array('phone_e164', $headers, true)) {
            fclose($handle);
            throw new \RuntimeException('CSV must include a Phone, Mobile, Phone Number, or phone_e164 column.');
        }

        $defaultCountry = strtoupper(trim((string) data_get($operation->options, 'default_country')));
        $defaultCountry = $defaultCountry !== '' ? $defaultCountry : null;
        if ($defaultCountry !== null && $normaliser->countryToCallingCode($defaultCountry) === null) {
            fclose($handle);
            throw new \RuntimeException('Choose a valid two-letter default country code, such as LB, BD, or US.');
        }

        $hasCountryColumn = in_array('country', $headers, true);
        $contactService = app(ContactService::class);
        $seen = [];
        $summary = [
            'accepted' => 0,
            'missing_phone' => 0,
            'missing_country' => 0,
            'invalid_country' => 0,
            'invalid_phone' => 0,
            'malformed_row' => 0,
            'duplicate_in_file' => 0,
            'existing_customer' => 0,
        ];
        $total = 0;
        $maxRows = (int) config('contact_imports.max_rows_per_file');
        $buffer = [];

        $flush = function () use (&$buffer, &$summary, $operation): void {
            if ($buffer === []) {
                return;
            }
            $phones = array_keys($buffer);
            $existing = Contact::withTrashed()
                ->where('workspace_id', $operation->workspace_id)
                ->customerDirectory()
                ->whereIn('phone_e164', $phones)
                ->pluck('phone_e164')
                ->all();
            $existingLookup = array_fill_keys($existing, true);
            foreach ($phones as $phone) {
                if (isset($existingLookup[$phone])) {
                    $summary['existing_customer']++;
                } else {
                    $summary['accepted']++;
                }
            }
            $buffer = [];
        };

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === [] || $line === ['']) {
                continue;
            }
            $total++;
            if ($total > $maxRows) {
                fclose($handle);
                throw new \RuntimeException(
                    'This CSV exceeds the '.number_format($maxRows).'-contact limit. Split the audience into smaller CSV files and upload each file to this same Contact List.'
                );
            }
            if (count($line) !== count($headers)) {
                $summary['malformed_row']++;

                continue;
            }
            $row = array_combine($headers, $line) ?: [];
            $rawPhone = trim((string) ($row['phone_e164'] ?? ''));
            $rowCountry = strtoupper(trim((string) ($row['country'] ?? '')));
            $country = $rowCountry !== '' ? $rowCountry : $defaultCountry;

            if ($rawPhone === '') {
                $summary['missing_phone']++;

                continue;
            }
            if ($rowCountry !== '' && $normaliser->countryToCallingCode($rowCountry) === null) {
                $summary['invalid_country']++;

                continue;
            }
            $normalised = $normaliser->normaliseRow($row, $operation->workspace_id, $country, $contactService);
            if ($normalised === null) {
                $digits = preg_replace('/\D/', '', $rawPhone) ?: '';
                $isInternational = str_starts_with($rawPhone, '+') || str_starts_with($digits, '00') || strlen($digits) >= 10;
                $summary[$isInternational ? 'invalid_phone' : 'missing_country']++;

                continue;
            }
            if (isset($seen[$normalised['phone_e164']])) {
                $summary['duplicate_in_file']++;

                continue;
            }
            $seen[$normalised['phone_e164']] = true;
            $buffer[$normalised['phone_e164']] = true;
            if (count($buffer) >= 1000) {
                $flush();
            }

            if ($total % 1000 === 0) {
                $this->reportProgress($operation, $total, $summary);
            }
        }
        fclose($handle);
        $flush();

        $rejected = $total - $summary['accepted'];
        $operation->update([
            'status' => 'completed',
            'total' => $total,
            'processed' => $total,
            // `added` is used as the accepted/ready count for this validation
            // record; no contacts have been created yet.
            'added' => $summary['accepted'],
            'skipped' => $rejected,
            'skipped_existing_customer' => $summary['existing_customer'],
            'skipped_invalid_phone' => $summary['missing_phone'] + $summary['missing_country'] + $summary['invalid_country'] + $summary['invalid_phone'],
            'skipped_malformed_row' => $summary['malformed_row'],
            'skipped_duplicate_in_file' => $summary['duplicate_in_file'],
            'options' => array_merge($operation->options ?? [], ['validation' => $summary]),
            'finished_at' => now(),
        ]);
    }

    private function reportProgress(ContactListOperation $operation, int $total, array $summary): void
    {
        $operation->update([
            'total' => $total,
            'processed' => $total,
            'added' => $summary['accepted'],
            'skipped' => $total - $summary['accepted'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $operation = ContactListOperation::find($this->operationId);
        if ($operation === null) {
            return;
        }

        Storage::disk('local')->delete((string) $operation->source_path);
        $operation->update([
            'status' => 'failed',
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'finished_at' => now(),
        ]);
    }
}
