<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One inbox: the signed-in account's notifications of one audience. The
 * member app, the dashboard and the provider portal each read their own, so
 * a notice never shows in an inbox it was not meant for.
 */
trait ServesNotificationInbox
{
    abstract protected function inboxAudience(): NotificationAudience;

    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()
            ->notifications()
            ->forAudience($this->inboxAudience())
            ->paginate(20);

        return response()->json([
            'data' => NotificationResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $request->user()
            ->notifications()
            ->forAudience($this->inboxAudience())
            ->findOrFail($id)
            ->markAsRead();

        return response()->json(['message' => __('api.notification.marked_as_read')]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()
            ->unreadNotifications()
            ->forAudience($this->inboxAudience())
            ->update(['read_at' => now()]);

        return response()->json(['message' => __('api.notification.all_marked_as_read')]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()
            ->unreadNotifications()
            ->forAudience($this->inboxAudience())
            ->count();

        return response()->json(['unread_count' => $count]);
    }
}
