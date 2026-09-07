<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WorkspaceNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = WorkspaceNotifications::forRequest($request)->latest()->paginate(25);

        return response()->json($notifications);
    }

    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = WorkspaceNotifications::forRequest($request)->findOrFail($notificationId);
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }
}
