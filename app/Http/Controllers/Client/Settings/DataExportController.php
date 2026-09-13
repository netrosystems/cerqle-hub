<?php

namespace App\Http\Controllers\Client\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateWorkspaceExportJob;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataExportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('client/Settings/DataExport', [
            'status' => session('export_status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->session()->get('current_workspace_id', $request->user()->workspace_id);
        abort_unless(Workspace::find($workspaceId)?->isAccessibleBy($request->user()), 403);
        GenerateWorkspaceExportJob::dispatch($request->user()->id, $workspaceId)
            ->onQueue('default');

        return back()->with('export_status', 'Your export is being generated. You will receive an email with the download link shortly.');
    }
}
