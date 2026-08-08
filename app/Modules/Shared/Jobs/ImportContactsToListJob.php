<?php

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactListOperation;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class ImportContactsToListJob implements ShouldQueue
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
        $segment = Segment::whereKey($operation->segment_id)
            ->where('workspace_id', $operation->workspace_id)
            ->where('type', 'static')
            ->firstOrFail();
        $contactService = app(ContactService::class);

        $operation->update(['status' => 'processing', 'started_at' => now(), 'error_message' => null]);
        $path = Storage::disk('local')->path((string) $operation->source_path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The uploaded CSV could not be opened.');
        }

        $headers = $this->normaliseHeaders(fgetcsv($handle) ?: []);
        if (! in_array('phone_e164', $headers, true)) {
            fclose($handle);
            throw new \RuntimeException('CSV must contain a phone_e164 or phone column.');
        }

        // The CSV's `country` column (if present) is how we place national
        // numbers that don't have a + prefix. If only some rows have a country,
        // we seed the parser with the first one we see and reuse it.
        $seenInFile = [];
        $defaultCountry = strtoupper(trim((string) data_get($operation->options, 'default_country')));
        $defaultCountry = $defaultCountry !== '' ? $defaultCountry : null;
        $hasCountryColumn = in_array('country', $headers, true);

        $buffer = [];
        $pendingInvalid = 0;
        $pendingMalformed = 0;
        $pendingDuplicate = 0;

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === [] || $line === ['']) {
                // Tolerate a trailing empty line from spreadsheet exports.
                continue;
            }

            if (count($line) !== count($headers)) {
                $pendingMalformed++;
                $this->flushSkippedCounts($operation, $pendingInvalid, $pendingMalformed, $pendingDuplicate);

                continue;
            }

            $combined = array_combine($headers, $line);
            $rowCountry = $hasCountryColumn ? strtoupper(trim((string) ($combined['country'] ?? ''))) : '';
            if ($rowCountry !== '' && $defaultCountry === null) {
                $defaultCountry = $rowCountry;
            }

            $normalised = $this->normaliseRow($combined ?: [], $operation->workspace_id, $defaultCountry, $contactService);
            if ($normalised === null) {
                $pendingInvalid++;
                $this->flushSkippedCounts($operation, $pendingInvalid, $pendingMalformed, $pendingDuplicate);

                continue;
            }

            if (isset($seenInFile[$normalised['phone_e164']])) {
                $pendingDuplicate++;
                $this->flushSkippedCounts($operation, $pendingInvalid, $pendingMalformed, $pendingDuplicate);

                continue;
            }
            $seenInFile[$normalised['phone_e164']] = true;

            $buffer[$normalised['phone_e164']] = $normalised;
            if (count($buffer) >= 1000) {
                $this->flushSkippedCounts($operation, $pendingInvalid, $pendingMalformed, $pendingDuplicate);
                $this->persistChunk($operation, $segment, array_values($buffer));
                $buffer = [];
            }
        }
        fclose($handle);

        $this->flushSkippedCounts($operation, $pendingInvalid, $pendingMalformed, $pendingDuplicate);
        if ($buffer !== []) {
            $this->persistChunk($operation, $segment, array_values($buffer));
        }

        $segment->update(['contact_count' => $segment->contacts()->count()]);
        $operation->update([
            'status' => 'completed',
            'total' => $operation->fresh()->processed,
            'finished_at' => now(),
        ]);
        Storage::disk('local')->delete((string) $operation->source_path);
    }

    /**
     * Persist accumulated skip counters in one UPDATE so the UI can poll a
     * granular breakdown instead of a single "skipped" total.
     */
    private function flushSkippedCounts(ContactListOperation $operation, int &$invalid, int &$malformed, int &$duplicate): void
    {
        if ($invalid > 0) {
            $operation->increment('skipped_invalid_phone', $invalid);
            $operation->increment('skipped', $invalid);
            $invalid = 0;
        }
        if ($malformed > 0) {
            $operation->increment('skipped_malformed_row', $malformed);
            $operation->increment('skipped', $malformed);
            $malformed = 0;
        }
        if ($duplicate > 0) {
            $operation->increment('skipped_duplicate_in_file', $duplicate);
            $operation->increment('skipped', $duplicate);
            $duplicate = 0;
        }
    }

    private function persistChunk(ContactListOperation $operation, Segment $segment, array $rows): void
    {
        $phones = array_column($rows, 'phone_e164');
        $existing = Contact::withTrashed()
            ->where('workspace_id', $operation->workspace_id)
            ->whereIn('phone_e164', $phones)
            ->pluck('id', 'phone_e164');

        // A spreadsheet recipient may have the same number as an existing CRM
        // customer. Do not overwrite that customer's profile with campaign data,
        // but report those rows separately so the UI can explain why they never
        // appeared in the list.
        $crmCustomerPhones = Contact::withTrashed()
            ->where('workspace_id', $operation->workspace_id)
            ->customerDirectory()
            ->whereIn('phone_e164', $phones)
            ->pluck('phone_e164')
            ->all();
        $campaignAudienceRows = array_values(array_filter(
            $rows,
            fn (array $row) => ! in_array($row['phone_e164'], $crmCustomerPhones, true)
        ));

        if ($campaignAudienceRows !== []) {
            Contact::query()->upsert(
                $campaignAudienceRows,
                ['workspace_id', 'phone_e164'],
                ['first_name', 'last_name', 'email', 'country', 'language', 'opt_in_sms', 'source', 'deleted_at', 'updated_at']
            );
        }

        $contacts = Contact::where('workspace_id', $operation->workspace_id)
            ->whereIn('phone_e164', $phones)
            ->where('is_campaign_only', true)
            ->pluck('id', 'phone_e164');
        $pivotRows = $contacts->map(fn ($id) => ['segment_id' => $segment->id, 'contact_id' => $id])->values()->all();
        $addedToList = DB::table('segment_contact')->insertOrIgnore($pivotRows);

        $operation->increment('processed', count($rows));
        $operation->increment('added', $addedToList);
        $operation->increment('updated', $existing->count());
        $operation->increment('skipped_existing_customer', count($crmCustomerPhones));
    }

    public function normaliseHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $key = Str::snake(strtolower(trim((string) $header)));

            return match ($key) {
                'phone', 'mobile', 'mobile_number', 'phone_number', 'telephone', 'cellphone', 'cell_phone' => 'phone_e164',
                'firstname' => 'first_name',
                'lastname' => 'last_name',
                'opt_in_s_m_s', 'sms_opt_in', 'sms_consent' => 'opt_in_sms',
                default => $key,
            };
        }, $headers);
    }

    public function normaliseRow(array $row, int $workspaceId, ?string $defaultCountry, ContactService $contactService): ?array
    {
        $phone = $this->normaliseInternationalPhone((string) ($row['phone_e164'] ?? ''), $defaultCountry);
        if ($phone === null) {
            return null;
        }

        // Prefer the country given in the row, then fall back to the
        // first country we saw earlier in the file.
        $country = mb_substr(strtoupper(trim((string) ($row['country'] ?? ''))), 0, 4) ?: ($defaultCountry ?? null);

        $now = now();

        return [
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'phone_e164' => $phone,
            'email' => filter_var($row['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'first_name' => mb_substr(trim((string) ($row['first_name'] ?? '')), 0, 128) ?: null,
            'last_name' => mb_substr(trim((string) ($row['last_name'] ?? '')), 0, 128) ?: null,
            'country' => $country ? mb_substr($country, 0, 4) : null,
            'language' => mb_substr(strtolower(trim((string) ($row['language'] ?? ''))), 0, 8) ?: null,
            // Uploading a contact list IS the consent signal. Only honor
            // explicit opt-out tokens (see ContactService::coerceOptIn).
            'opt_in_sms' => $contactService->coerceOptIn($row['opt_in_sms'] ?? null),
            'source' => 'contact_list_csv',
            'is_campaign_only' => true,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Normalise common phone formats to E.164.
     *
     * Accepted shapes:
     *   - E.164 already (+96170123456)
     *   - 00-prefixed international (0096170123456)
     *   - Bare international digits (96170123456)
     *   - National digits with a known country prefix (070123456 + "LB" -> +96170123456)
     *
     * Truly local numbers with no resolvable country are rejected because
     * we cannot send to them safely without a guess.
     */
    public function normaliseInternationalPhone(string $value, ?string $defaultCountry = null): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        $region = null;
        if (str_starts_with($trimmed, '+')) {
            $candidate = '+'.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $candidate = '+'.substr($digits, 2);
        } elseif ($defaultCountry !== null && $defaultCountry !== '') {
            $callingCode = $this->countryToCallingCode($defaultCountry);
            if ($callingCode === null) return null;

            // A CSV frequently loses the + sign. If the value already starts
            // with the selected country's calling code, treat it as
            // international; otherwise parse it as a national number.
            $candidate = str_starts_with($digits, $callingCode) ? '+'.$digits : $digits;
            $region = $candidate === $digits ? $defaultCountry : null;
        } elseif (preg_match('/^[1-9]\d{7,14}$/', $digits) === 1) {
            $candidate = '+'.$digits;
        } else {
            return null;
        }

        try {
            $utility = PhoneNumberUtil::getInstance();
            $parsed = $utility->parse($candidate, $region);
            if (! $utility->isValidNumber($parsed)) return null;

            return $utility->format($parsed, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            return null;
        }
    }

    /**
     * Resolve an ISO 3166-1 alpha-2 country code (e.g. "LB") to its international
     * calling code (e.g. "961"). Returns null for unknown codes — those rows
     * fall through to the safety check below.
     */
    public function countryToCallingCode(string $country): ?string
    {
        $codes = [
            'AC' => '247', 'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1268',
            'AI' => '1264', 'AL' => '355', 'AM' => '374', 'AO' => '244', 'AR' => '54',
            'AS' => '1684', 'AT' => '43', 'AU' => '61', 'AW' => '297', 'AX' => '358',
            'AZ' => '994', 'BA' => '387', 'BB' => '1246', 'BD' => '880', 'BE' => '32',
            'BF' => '226', 'BG' => '359', 'BH' => '973', 'BI' => '257', 'BJ' => '229',
            'BL' => '590', 'BM' => '1441', 'BN' => '673', 'BO' => '591', 'BQ' => '599',
            'BR' => '55', 'BS' => '1242', 'BT' => '975', 'BW' => '267', 'BY' => '375',
            'BZ' => '501', 'CA' => '1', 'CC' => '61', 'CD' => '243', 'CF' => '236',
            'CG' => '242', 'CH' => '41', 'CI' => '225', 'CK' => '682', 'CL' => '56',
            'CM' => '237', 'CN' => '86', 'CO' => '57', 'CR' => '506', 'CU' => '53',
            'CV' => '238', 'CW' => '599', 'CX' => '61', 'CY' => '357', 'CZ' => '420',
            'DE' => '49', 'DJ' => '253', 'DK' => '45', 'DM' => '1767', 'DO' => '1809',
            'DZ' => '213', 'EC' => '593', 'EE' => '372', 'EG' => '20', 'EH' => '212',
            'ER' => '291', 'ES' => '34', 'ET' => '251', 'FI' => '358', 'FJ' => '679',
            'FK' => '500', 'FM' => '691', 'FO' => '298', 'FR' => '33', 'GA' => '241',
            'GB' => '44', 'GD' => '1473', 'GE' => '995', 'GF' => '594', 'GG' => '44',
            'GH' => '233', 'GI' => '350', 'GL' => '299', 'GM' => '220', 'GN' => '224',
            'GP' => '590', 'GQ' => '240', 'GR' => '30', 'GT' => '502', 'GU' => '1671',
            'GW' => '245', 'GY' => '592', 'HK' => '852', 'HN' => '504', 'HR' => '385',
            'HT' => '509', 'HU' => '36', 'ID' => '62', 'IE' => '353', 'IL' => '972',
            'IM' => '44', 'IN' => '91', 'IO' => '246', 'IQ' => '964', 'IR' => '98',
            'IS' => '354', 'IT' => '39', 'JE' => '44', 'JM' => '1876', 'JO' => '962',
            'JP' => '81', 'KE' => '254', 'KG' => '996', 'KH' => '855', 'KI' => '686',
            'KM' => '269', 'KN' => '1869', 'KP' => '850', 'KR' => '82', 'KW' => '965',
            'KY' => '1345', 'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1758',
            'LI' => '423', 'LK' => '94', 'LR' => '231', 'LS' => '266', 'LT' => '370',
            'LU' => '352', 'LV' => '371', 'LY' => '218', 'MA' => '212', 'MC' => '377',
            'MD' => '373', 'ME' => '382', 'MF' => '590', 'MG' => '261', 'MH' => '692',
            'MK' => '389', 'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853',
            'MP' => '1670', 'MQ' => '596', 'MR' => '222', 'MS' => '1664', 'MT' => '356',
            'MU' => '230', 'MV' => '960', 'MW' => '265', 'MX' => '52', 'MY' => '60',
            'MZ' => '258', 'NA' => '264', 'NC' => '687', 'NE' => '227', 'NF' => '672',
            'NG' => '234', 'NI' => '505', 'NL' => '31', 'NO' => '47', 'NP' => '977',
            'NR' => '674', 'NU' => '683', 'NZ' => '64', 'OM' => '968', 'PA' => '507',
            'PE' => '51', 'PF' => '689', 'PG' => '675', 'PH' => '63', 'PK' => '92',
            'PL' => '48', 'PM' => '508', 'PR' => '1787', 'PS' => '970', 'PT' => '351',
            'PW' => '680', 'PY' => '595', 'QA' => '974', 'RE' => '262', 'RO' => '40',
            'RS' => '381', 'RU' => '7', 'RW' => '250', 'SA' => '966', 'SB' => '677',
            'SC' => '248', 'SD' => '249', 'SE' => '46', 'SG' => '65', 'SH' => '290',
            'SI' => '386', 'SJ' => '47', 'SK' => '421', 'SL' => '232', 'SM' => '378',
            'SN' => '221', 'SO' => '252', 'SR' => '597', 'SS' => '211', 'ST' => '239',
            'SV' => '503', 'SX' => '1721', 'SY' => '963', 'SZ' => '268', 'TA' => '290',
            'TC' => '1649', 'TD' => '235', 'TG' => '228', 'TH' => '66', 'TJ' => '992',
            'TK' => '690', 'TL' => '670', 'TM' => '993', 'TN' => '216', 'TO' => '676',
            'TR' => '90', 'TT' => '1868', 'TV' => '688', 'TW' => '886', 'TZ' => '255',
            'UA' => '380', 'UG' => '256', 'US' => '1', 'UY' => '598', 'UZ' => '998',
            'VA' => '379', 'VC' => '1784', 'VE' => '58', 'VG' => '1284', 'VI' => '1340',
            'VN' => '84', 'VU' => '678', 'WF' => '681', 'WS' => '685', 'XK' => '383',
            'YE' => '967', 'YT' => '262', 'ZA' => '27', 'ZM' => '260', 'ZW' => '263',
        ];

        return $codes[$country] ?? null;
    }


    public function failed(\Throwable $exception): void
    {
        ContactListOperation::whereKey($this->operationId)->update([
            'status' => 'failed',
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'finished_at' => now(),
        ]);
    }
}
