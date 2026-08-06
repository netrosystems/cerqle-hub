<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Jobs\AddContactsToListJob;
use App\Modules\Shared\Jobs\ImportContactsToListJob;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactListOperation;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\SegmentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SegmentController extends Controller
{
    public function __construct(private SegmentResolver $resolver) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $segments = Segment::where('workspace_id', $workspaceId)->latest()->get();

        return Inertia::render('Contacts/Segments', ['segments' => $segments]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', 'in:static,dynamic'],
            'rules_json' => ['nullable', 'array'],
        ]);

        $segment = Segment::create(array_merge($validated, ['workspace_id' => $workspaceId]));

        if ($segment->type === 'dynamic') {
            $this->resolver->materialise($segment);
        }

        return back()->with('success', 'Contact list created.');
    }

    public function update(Request $request, Segment $segment): RedirectResponse
    {
        $this->authorise($request, $segment);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'rules_json' => ['nullable', 'array'],
        ]);
        $segment->update($validated);

        if ($segment->type === 'dynamic') {
            $this->resolver->materialise($segment);
        }

        return back()->with('success', 'Contact list updated.');
    }

    public function destroy(Request $request, Segment $segment): RedirectResponse
    {
        $this->authorise($request, $segment);
        $segment->contacts()->detach();
        $segment->delete();

        return back()->with('success', 'Contact list deleted.');
    }

    public function manageContacts(Request $request, Segment $segment): Response
    {
        $this->authorise($request, $segment);
        abort_if($segment->type !== 'static', 403, 'Only static contact lists support manual contact management.');

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $search = trim((string) $request->input('search', ''));

        $existingContactsCount = $segment->contacts()
            ->where('contacts.is_campaign_only', false)
            ->count();
        $uploadedContactsCount = $segment->contacts()
            ->where('contacts.is_campaign_only', true)
            ->count();

        // Single, merged "already in this list" view: real customers first, then
        // uploaded CSV recipients. Each row is tagged so the UI can render a
        // source chip without second queries.
        $listContacts = $segment->contacts()
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('contacts.first_name', 'like', '%'.$search.'%')
                    ->orWhere('contacts.last_name', 'like', '%'.$search.'%')
                    ->orWhere('contacts.phone_e164', 'like', '%'.$search.'%')
                    ->orWhere('contacts.email', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('contacts.is_campaign_only')
            ->orderByDesc('contacts.id')
            ->paginate(25, ['contacts.id', 'contacts.uuid', 'first_name', 'last_name', 'phone_e164', 'email', 'avatar', 'is_campaign_only'], 'list_page')
            ->through(fn ($contact) => [
                'id' => $contact->id,
                'uuid' => $contact->uuid,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'phone_e164' => $contact->phone_e164,
                'email' => $contact->email,
                'avatar' => $contact->avatar,
                'source' => $contact->is_campaign_only ? 'uploaded' : 'existing',
            ])
            ->withQueryString();

        $availableQuery = Contact::where('workspace_id', $workspaceId)
            ->customerDirectory()
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('phone_e164', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->whereDoesntHave('segments', fn ($q) => $q->whereKey($segment->id));

        $availableCount = (clone $availableQuery)->count();
        $allContacts = $availableQuery
            ->orderByDesc('id')
            ->paginate(50, ['id', 'uuid', 'first_name', 'last_name', 'phone_e164', 'email', 'avatar'], 'available_page')
            ->withQueryString();

        return Inertia::render('Contacts/SegmentContacts', [
            'segment' => $segment,
            'listContacts' => $listContacts,
            'existingContactsCount' => $existingContactsCount,
            'uploadedContactsCount' => $uploadedContactsCount,
            'availableContacts' => $allContacts,
            'availableCount' => $availableCount,
            'filters' => ['search' => $search],
            'operations' => ContactListOperation::where('workspace_id', $workspaceId)
                ->where('segment_id', $segment->id)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function attachContacts(Request $request, Segment $segment): RedirectResponse
    {
        $this->authorise($request, $segment);
        abort_if($segment->type !== 'static', 403);

        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        $validated = $request->validate([
            'selection' => ['required', 'in:selected,all'],
            'contact_ids' => ['required_if:selection,selected', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['integer'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        if ($validated['selection'] === 'all') {
            $search = trim((string) ($validated['search'] ?? ''));
            $query = Contact::where('workspace_id', $workspaceId)
                ->customerDirectory()
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('phone_e164', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                }))
                ->whereDoesntHave('segments', fn ($q) => $q->whereKey($segment->id));

            $operation = ContactListOperation::create([
                'workspace_id' => $workspaceId,
                'segment_id' => $segment->id,
                'created_by' => $request->user()->id,
                'type' => 'add_existing',
                'status' => 'queued',
                'total' => (clone $query)->count(),
                'options' => [
                    'search' => $search,
                    'max_contact_id' => (int) ((clone $query)->max('contacts.id') ?? 0),
                ],
            ]);
            AddContactsToListJob::dispatch($operation->id);

            return back()->with('success', number_format($operation->total).' contact(s) queued for this contact list.');
        }

        $contactIds = Contact::where('workspace_id', $workspaceId)
            ->customerDirectory()
            ->whereIn('id', $validated['contact_ids'])
            ->pluck('id');
        $segment->contacts()->syncWithoutDetaching($contactIds);
        $segment->update(['contact_count' => $segment->contacts()->count()]);

        return back()->with('success', $contactIds->count().' contact(s) added to the contact list.');
    }

    public function importContacts(Request $request, Segment $segment): RedirectResponse
    {
        $this->authorise($request, $segment);
        abort_if($segment->type !== 'static', 403);

        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:102400'],
        ]);
        $path = $validated['file']->store('contact-list-imports', 'local');

        $operation = ContactListOperation::create([
            'workspace_id' => $workspaceId,
            'segment_id' => $segment->id,
            'created_by' => $request->user()->id,
            'type' => 'csv_import',
            'status' => 'queued',
            'source_path' => $path,
        ]);
        ImportContactsToListJob::dispatch($operation->id);

        return back()->with('success', 'CSV accepted. Contacts will be validated and added in the background.');
    }

    public function downloadSampleCsv(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['phone_e164', 'first_name', 'last_name', 'email', 'country', 'language', 'opt_in_sms']);
            fputcsv($output, ['+96170123456', 'Ada', 'Lovelace', 'ada@example.com', 'LB', 'en', 'true']);
            fputcsv($output, ['+8801712345678', 'Rahim', 'Ahmed', 'rahim@example.com', 'BD', 'bn', 'true']);
            fclose($output);
        }, 'cerqle-contact-list-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function detachAllContacts(Request $request, Segment $segment): RedirectResponse
    {
        $this->authorise($request, $segment);
        abort_if($segment->type !== 'static', 403);

        // This only removes CRM contacts from this list. It never deletes a
        // customer record, and keeps the separately-uploaded campaign audience.
        $segment->contacts()
            ->customerDirectory()
            ->select('contacts.id')
            ->orderBy('contacts.id')
            ->chunkById(1000, function ($contacts) use ($segment): void {
                $segment->contacts()->detach($contacts->pluck('id')->all());
            }, 'contacts.id', 'id');

        $segment->update(['contact_count' => $segment->contacts()->count()]);

        return back()->with('success', 'All existing contacts were removed from this contact list. Customer records were not deleted.');
    }

    public function detachContact(Request $request, Segment $segment, Contact $contact): RedirectResponse
    {
        $this->authorise($request, $segment);
        abort_if($segment->type !== 'static', 403);

        $segment->contacts()->detach($contact->id);
        $segment->update(['contact_count' => $segment->contacts()->count()]);

        return back()->with('success', 'Contact removed from the contact list.');
    }

    public function clearList(Request $request, Segment $segment): RedirectResponse
    {
        $this->authorise($request, $segment);
        abort_if($segment->type !== 'static', 403);

        $before = $segment->contacts()->count();

        // Detach in chunks so very large lists don't blow up memory.
        // Note: this only removes the pivot rows; it never deletes the
        // underlying Contact records (CRM customers stay in the directory,
        // uploaded recipients stay in the contact table but lose their
        // membership in this list).
        $segment->contacts()
            ->select('contacts.id')
            ->orderBy('contacts.id')
            ->chunkById(1000, function ($contacts) use ($segment): void {
                $segment->contacts()->detach($contacts->pluck('id')->all());
            }, 'contacts.id', 'id');

        $segment->update(['contact_count' => 0]);

        return back()->with('success', "Removed {$before} contact(s) from this contact list. Customer records were not deleted.");
    }

    private function authorise(Request $request, Segment $segment): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $segment->workspace_id === (int) $workspaceId, 403);
    }
}
