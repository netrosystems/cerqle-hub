<?php

namespace App\Modules\Broadcasting\Services;

use App\Modules\Shared\Jobs\ImportContactsToListJob;
use App\Modules\Shared\Services\ContactService;

class CampaignCsvService
{
    /**
     * Validate an SMS or WhatsApp campaign CSV without changing contacts.
     *
     * @return array{rows: int, eligible: int, skipped: int, ignored_over_limit: int}
     */
    public function inspect(string $path, int $workspaceId, string $channel = 'sms'): array
    {
        $normaliser = new ImportContactsToListJob(0);
        $normaliser->assertPhoneValidationAvailable();

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The uploaded CSV could not be opened.');
        }

        try {
            $headers = $normaliser->normaliseHeaders(fgetcsv($handle, null, ',', '"', '') ?: []);
            if (! in_array('phone_e164', $headers, true)) {
                throw new \RuntimeException('CSV must include a Phone, Mobile, Phone Number, or phone_e164 column.');
            }

            $maxRows = (int) config('contact_imports.max_rows_per_file');
            $contactService = app(ContactService::class);
            $seen = [];
            $rows = 0;
            $eligible = 0;
            $skipped = 0;
            $ignored = 0;

            while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($line === [null] || $line === [] || $line === ['']) {
                    continue;
                }

                $rows++;
                if ($rows > $maxRows) {
                    $ignored++;

                    continue;
                }

                if (count($line) !== count($headers)) {
                    $skipped++;

                    continue;
                }

                $row = array_combine($headers, $line) ?: [];
                $country = strtoupper(trim((string) ($row['country'] ?? ''))) ?: null;
                $normalised = $normaliser->normaliseRow($row, $workspaceId, $country, $contactService);
                $phone = $normalised['phone_e164'] ?? null;

                $consent = $channel === 'whatsapp' ? 'opt_in_whatsapp' : 'opt_in_sms';
                if ($normalised === null || ! ($normalised[$consent] ?? false) || isset($seen[$phone])) {
                    $skipped++;

                    continue;
                }

                $seen[$phone] = true;
                $eligible++;
            }

            return compact('rows', 'eligible', 'skipped') + ['ignored_over_limit' => $ignored];
        } finally {
            fclose($handle);
        }
    }
}
