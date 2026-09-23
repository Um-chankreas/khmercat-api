<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Paginated list, newest first — every notification (read and unread),
     * the app distinguishes them client-side via `is_read`.
     */
    public function index(Request $request)
    {
        $notifications = auth()->user()->notifications()
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'contents' => $notifications->getCollection()->map(fn ($n) => $this->transform($n)),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'has_more' => $notifications->hasMorePages(),
                'unread_count' => auth()->user()->unreadNotifications()->count(),
            ],
        ], 'Notifications retrieved successfully.');
    }

    public function markRead(string $id)
    {
        $notification = auth()->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return ApiResponse::error('Notification not found.', 404);
        }

        $notification->markAsRead();

        return ApiResponse::success(null, 'Notification marked as read.');
    }

    public function markAllRead()
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked as read.');
    }

    public function destroy(string $id)
    {
        $deleted = auth()->user()->notifications()->where('id', $id)->delete();

        if (! $deleted) {
            return ApiResponse::error('Notification not found.', 404);
        }

        return ApiResponse::success(null, 'Notification deleted.');
    }

    public function destroyAll()
    {
        auth()->user()->notifications()->delete();

        return ApiResponse::success(null, 'All notifications deleted.');
    }

    private function transform($notification): array
    {
        return [
            'id' => $notification->id,
            'is_read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toJSON(),
            ...$notification->data,
        ];
    }
}
