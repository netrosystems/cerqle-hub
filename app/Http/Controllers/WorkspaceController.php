<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\WorkspaceDeletionService;
use App\Services\WorkspaceLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * List workspaces the user can access (for switcher).
     */
    public function index(Request $request, WorkspaceLimitService $workspaceLimits): Response
    {
        $workspaces = $request->user()->accessibleWorkspaces();

        return Inertia::render('client/Workspaces/Index', [
            'workspaces' => $workspaces->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'is_owner' => $w->owner_id === $request->user()->id,
                'can_update' => $request->user()->can('update', $w),
                'can_delete' => $request->user()->can('delete', $w),
            ]),
            'workspaceUsage' => $workspaceLimits->usageFor($request->user()),
        ]);
    }

    /**
     * Switch current workspace (set session and update user.workspace_id).
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
        ]);

        $workspace = Workspace::findOrFail($validated['workspace_id']);

        $this->authorize('view', $workspace);

        $request->session()->put('current_workspace_id', $workspace->id);
        $request->user()->update(['workspace_id' => $workspace->id]);

        return redirect()->intended(route('client.dashboard'));
    }

    /**
     * Create a new workspace (owner is current user).
     */
    public function store(Request $request, WorkspaceLimitService $workspaceLimits): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $request->merge(['name' => trim((string) $request->input('name'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace = $workspaceLimits->createFor($request->user(), $validated);

        $request->session()->put('current_workspace_id', $workspace->id);
        $request->user()->update(['workspace_id' => $workspace->id]);

        return redirect()->route('client.dashboard')->with('success', __('Workspace created.'));
    }

    /** Rename a workspace without changing its ownership or data. */
    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace->update($validated);

        return redirect()->back()->with('success', __('Workspace updated.'));
    }

    /** Permanently delete a workspace and all of its scoped data. */
    public function destroy(
        Request $request,
        Workspace $workspace,
        WorkspaceDeletionService $deletionService,
    ): RedirectResponse {
        $this->authorize('delete', $workspace);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', Rule::in([$workspace->name])],
        ], [
            'confirmation.in' => __('Type the workspace name exactly to confirm deletion.'),
        ]);

        // Reading the validated value is intentional: it ensures the destructive
        // confirmation can never be removed as an apparently-unused validation.
        unset($validated['confirmation']);

        $nextWorkspaceId = $deletionService->delete($workspace, $request->user());
        if ($nextWorkspaceId) {
            $request->session()->put('current_workspace_id', $nextWorkspaceId);
        } else {
            $request->session()->forget('current_workspace_id');
        }

        return redirect()->route('client.workspaces.index')->with('success', __('Workspace and all related data deleted.'));
    }
}
