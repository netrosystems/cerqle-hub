<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()->accessibleWorkspaces()->map(fn ($w) => [
            'id' => $w->id,
            'name' => $w->name,
            'role' => $request->user()->workspaceRole($w),
            'is_current' => (int) $request->user()->workspace_id === (int) $w->id,
            'currency_code' => $w->currency_code,
            'created_at' => $w->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => $workspaces]);
    }

    /**
     * Select the workspace used by subsequent mobile API requests.
     */
    public function select(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($request->user()->canAccessWorkspace($workspace), 403);

        $request->user()->update(['workspace_id' => $workspace->id]);

        return response()->json([
            'ok' => true,
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $request->user()->workspaceRole($workspace),
                'currency_code' => $workspace->currency_code,
            ],
        ]);
    }
}
