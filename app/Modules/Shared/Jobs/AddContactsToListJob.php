<?php

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactListOperation;
use App\Modules\Shared\Models\Segment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AddContactsToListJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 3;

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
        $options = $operation->options ?? [];
        $search = trim((string) ($options['search'] ?? ''));
        $maxContactId = (int) ($options['max_contact_id'] ?? 0);

        $query = Contact::query()
            ->where('workspace_id', $operation->workspace_id)
            ->when($maxContactId > 0, fn ($q) => $q->where('id', '<=', $maxContactId))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('phone_e164', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->whereDoesntHave('segments', fn ($q) => $q->whereKey($segment->id));

        $query->select('contacts.id')->chunkById(2000, function ($contacts) use ($operation, $segment) {
            $rows = $contacts->map(fn ($contact) => [
                'segment_id' => $segment->id,
                'contact_id' => $contact->id,
            ])->all();

            $added = DB::table('segment_contact')->insertOrIgnore($rows);
            $operation->increment('processed', count($rows));
            $operation->increment('added', $added);
        }, 'contacts.id', 'id');

        $segment->update(['contact_count' => $segment->contacts()->count()]);
        $operation->update(['status' => 'completed', 'finished_at' => now()]);
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
