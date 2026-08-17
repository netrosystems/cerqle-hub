<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Shared helpers for workspace-scoped API controllers.
 */
abstract class WorkspaceScopedController extends Controller
{
    /**
     * Resolve the workspace_id for the authenticated API request.
     * The mobile workspace selector persists the agent's active workspace on
     * users.workspace_id, so token-authenticated requests do not depend on a
     * browser session.
     */
    protected function workspaceId(Request $request): int
    {
        return (int) $request->user()->workspace_id;
    }
}
