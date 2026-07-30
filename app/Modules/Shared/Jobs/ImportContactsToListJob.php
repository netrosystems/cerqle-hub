<?php

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactListOperation;
use App\Modules\Shared\Models\Segment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $buffer = [];
        $pendingSkipped = 0;
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) !== count($headers)) {
                $pendingSkipped++;

                if ($pendingSkipped >= 1000) {
                    $this->recordSkipped($operation, $pendingSkipped);
                    $pendingSkipped = 0;
                }

                continue;
            }

            $row = array_combine($headers, $line);
            $normalised = $this->normaliseRow($row ?: [], $operation->workspace_id);
            if ($normalised === null) {
                $pendingSkipped++;

                if ($pendingSkipped >= 1000) {
                    $this->recordSkipped($operation, $pendingSkipped);
                    $pendingSkipped = 0;
                }

                continue;
            }

            if (isset($buffer[$normalised['phone_e164']])) {
                $pendingSkipped++;
            }
            $buffer[$normalised['phone_e164']] = $normalised;
            if (count($buffer) >= 1000) {
                if ($pendingSkipped > 0) {
                    $this->recordSkipped($operation, $pendingSkipped);
                    $pendingSkipped = 0;
                }
                $this->persistChunk($operation, $segment, array_values($buffer));
                $buffer = [];
            }
        }
        fclose($handle);

        if ($pendingSkipped > 0) {
            $this->recordSkipped($operation, $pendingSkipped);
        }
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

    private function recordSkipped(ContactListOperation $operation, int $count): void
    {
        $operation->increment('processed', $count);
        $operation->increment('skipped', $count);
    }

    private function persistChunk(ContactListOperation $operation, Segment $segment, array $rows): void
    {
        $phones = array_column($rows, 'phone_e164');
        $existing = Contact::withTrashed()
            ->where('workspace_id', $operation->workspace_id)
            ->whereIn('phone_e164', $phones)
            ->pluck('id', 'phone_e164');

        Contact::query()->upsert(
            $rows,
            ['workspace_id', 'phone_e164'],
            ['first_name', 'last_name', 'email', 'country', 'language', 'opt_in_sms', 'source', 'deleted_at', 'updated_at']
        );

        $contacts = Contact::where('workspace_id', $operation->workspace_id)
            ->whereIn('phone_e164', $phones)
            ->pluck('id', 'phone_e164');
        $pivotRows = $contacts->map(fn ($id) => ['segment_id' => $segment->id, 'contact_id' => $id])->values()->all();
        $addedToList = DB::table('segment_contact')->insertOrIgnore($pivotRows);

        $operation->increment('processed', count($rows));
        $operation->increment('added', $addedToList);
        $operation->increment('updated', $existing->count());
    }

    private function normaliseHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $key = Str::snake(strtolower(trim((string) $header)));

            return match ($key) {
                'phone', 'mobile', 'mobile_number', 'phone_number' => 'phone_e164',
                'firstname' => 'first_name',
                'lastname' => 'last_name',
                default => $key,
            };
        }, $headers);
    }

    private function normaliseRow(array $row, int $workspaceId): ?array
    {
        $phone = preg_replace('/[^\d+]/', '', trim((string) ($row['phone_e164'] ?? '')));
        if (! is_string($phone) || ! preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            return null;
        }

        $now = now();

        return [
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'phone_e164' => $phone,
            'email' => filter_var($row['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'first_name' => mb_substr(trim((string) ($row['first_name'] ?? '')), 0, 128) ?: null,
            'last_name' => mb_substr(trim((string) ($row['last_name'] ?? '')), 0, 128) ?: null,
            'country' => mb_substr(strtoupper(trim((string) ($row['country'] ?? ''))), 0, 4) ?: null,
            'language' => mb_substr(strtolower(trim((string) ($row['language'] ?? ''))), 0, 8) ?: null,
            'opt_in_sms' => filter_var($row['opt_in_sms'] ?? true, FILTER_VALIDATE_BOOL),
            'source' => 'contact_list_csv',
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
