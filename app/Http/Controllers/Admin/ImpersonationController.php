<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function __construct(private AuditLogService $auditLog)
    {
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get('impersonator_admin_id');
        $clientId = $request->session()->get('impersonated_client_id');
        $session = $request->session();
        $wasImpersonating = (bool) $session->get('impersonating');

        $session->forget(['impersonator_admin_id', 'impersonating', 'impersonated_client_id']);

        Auth::guard('web')->logout();

        if ($wasImpersonating) {
            $this->auditLog->logAdmin('impersonation.ended', null, null, [
                'actor_admin_id' => $adminId,
                'client_id' => $clientId,
            ]);

            return redirect()
                ->route('admin.clients.index')
                ->with('success', __('Returned to admin.'));
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('success', __('Returned to admin.'));
    }
}
