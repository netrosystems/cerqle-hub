<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Services\WorkspaceNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * List unread notifications + preferences page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = WorkspaceNotifications::forRequest($request)->latest()->limit(50)->get()->map(fn ($n) => [
            'id' => $n->id,
            'type' => class_basename($n->type),
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ]);

        $preferences = $user->notificationPreferences->groupBy('event')->map(fn ($group) => $group->mapWithKeys(fn ($p) => [$p->channel => $p->enabled]));

        return Inertia::render('client/Notifications/Index', [
            'notifications' => $notifications,
            'preferences' => $preferences,
        ]);
    }

    /**
     * Return the most recent 10 notifications for the dropdown bell.
     */
    public function recent(Request $request): JsonResponse
    {
        $notifications = WorkspaceNotifications::forRequest($request)->latest()->limit(10)->get()->map(fn ($n) => [
            'id' => $n->id,
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ]);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => WorkspaceNotifications::forRequest($request)->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Mark one notification as read.
     */
    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = WorkspaceNotifications::forRequest($request)->findOrFail($notificationId);
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        WorkspaceNotifications::forRequest($request)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', __('All notifications marked as read.'));
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, string $notificationId): JsonResponse
    {
        WorkspaceNotifications::forRequest($request)->findOrFail($notificationId)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Update notification preferences.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.event' => ['required', 'string', 'max:100'],
            'preferences.*.channel' => ['required', 'string', 'in:mail,web_push,one_signal'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        foreach ($validated['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'event' => $pref['event'], 'channel' => $pref['channel']],
                ['enabled' => $pref['enabled']]
            );
        }

        return back()->with('success', __('Notification preferences updated.'));
    }
}
