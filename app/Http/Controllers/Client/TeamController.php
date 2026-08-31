<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceMembershipService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(private WorkspaceMembershipService $memberships) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->client_id || ! $user->isClientAdministrator()) {
            abort(403, __('Only client administrators can manage the team.'));
        }

        $client = $user->client;
        if (! $client) {
            abort(404);
        }

        $users = $client->users()
            ->with(['workspaces' => fn ($query) => $query->where('client_id', $client->id)->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status ?? 'active',
                'created_at' => $u->created_at->toIso8601String(),
                'workspace_assignments' => $u->workspaces->map(fn (Workspace $workspace) => [
                    'workspace_id' => $workspace->id,
                    'name' => $workspace->name,
                    'is_owner' => (int) $workspace->owner_id === (int) $u->id,
                    'role' => (int) $workspace->owner_id === (int) $u->id || $workspace->pivot->role === 'owner'
                        ? 'administrator'
                        : $workspace->pivot->role,
                ])->values(),
            ]);

        $workspaces = $client->workspaces()->orderBy('name')->get(['id', 'name', 'owner_id'])
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'owner_id' => $workspace->owner_id,
            ]);

        return Inertia::render('client/Team/Index', [
            'users' => $users,
            'client' => ['id' => $client->id, 'name' => $client->name],
            'invitations' => Invitation::where('client_id', $client->id)
                ->with('workspaces')
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Invitation $inv) => [
                    'id' => $inv->id,
                    'email' => $inv->email,
                    'expires_at' => $inv->expires_at->toIso8601String(),
                    'workspace_assignments' => $inv->workspaces->map(fn (Workspace $workspace) => [
                        'workspace_id' => $workspace->id,
                        'name' => $workspace->name,
                        'role' => $workspace->pivot->role,
                    ])->values(),
                ]),
            'workspaces' => $workspaces,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->client_id || ! $user->isClientAdministrator()) {
            abort(403, __('Only client administrators can manage the team.'));
        }

        $client = $user->client;
        if (! $client) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'workspace_assignments' => ['required', 'array', 'min:1'],
            'workspace_assignments.*.workspace_id' => ['required', 'integer'],
            'workspace_assignments.*.role' => ['required', 'in:administrator,staff'],
        ]);

        $member = DB::transaction(function () use ($client, $validated): User {
            $member = $client->users()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => User::ROLE_CLIENT,
                'client_id' => $client->id,
                // Workspace roles control permissions. New team members do not
                // receive organization-wide administration privileges.
                'client_role' => User::CLIENT_ROLE_STAFF,
                'status' => $validated['status'],
            ]);

            $this->memberships->sync($member, $client, $validated['workspace_assignments']);

            return $member;
        });

        // Dispatch only after the transaction commits so the standard email
        // verification listener can resolve the new member immediately.
        event(new Registered($member));

        return redirect()->route('client.team.index')->with('success', __('Team member added.'));
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $user = $request->user();
        if (! $user->client_id || ! $user->isClientAdministrator()) {
            abort(403, __('Only client administrators can manage the team.'));
        }

        $client = $user->client;
        if (! $client || $member->client_id !== $client->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::in([$member->email])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'workspace_assignments' => ['required', 'array', 'min:1'],
            'workspace_assignments.*.workspace_id' => ['required', 'integer'],
            'workspace_assignments.*.role' => ['required', 'in:administrator,staff'],
        ]);

        DB::transaction(function () use ($member, $client, $validated): void {
            $member->name = $validated['name'];
            $member->status = $validated['status'];
            if (! empty($validated['password'])) {
                $member->password = $validated['password'];
            }
            $member->save();

            $this->memberships->sync($member, $client, $validated['workspace_assignments']);
        });

        return redirect()->route('client.team.index')->with('success', __('Team member updated.'));
    }

    public function destroy(Request $request, User $member): RedirectResponse
    {
        $user = $request->user();
        if (! $user->client_id || ! $user->isClientAdministrator()) {
            abort(403, __('Only client administrators can manage the team.'));
        }

        $client = $user->client;
        if (! $client || $member->client_id !== $client->id) {
            abort(404);
        }

        $adminCount = $client->users()->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)->count();
        if ($member->client_role === User::CLIENT_ROLE_ADMINISTRATOR && $adminCount <= 1) {
            return redirect()->route('client.team.index')->with('error', __('Cannot remove the last administrator.'));
        }

        if ($member->id === $user->id) {
            return redirect()->route('client.team.index')->with('error', __('You cannot remove yourself.'));
        }

        $member->delete();

        return redirect()->route('client.team.index')->with('success', __('Team member removed.'));
    }
}
