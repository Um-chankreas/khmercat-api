<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\VideoReview;
use App\Notifications\CommentReplied;
use App\Notifications\RestaurantPostedVideo;
use App\Notifications\UserFollowed;
use App\Notifications\VideoCommented;
use App\Notifications\VideoLiked;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class NotificationController extends Controller
{
    /**
     * The app's filter tabs → the notification classes behind each one
     * (stored in the `type` column, so filtering needs no JSON queries).
     */
    private const FILTERS = [
        'likes' => [VideoLiked::class],
        'comments' => [VideoCommented::class, CommentReplied::class],
        'follows' => [UserFollowed::class],
        'updates' => [RestaurantPostedVideo::class],
    ];

    /**
     * Paginated list, newest first — read and unread (the app tells them
     * apart via `is_read`). Optional `filter`: likes | comments | follows |
     * updates. `meta.unread` has the unread count per filter (and `all`)
     * for the tab badges.
     *
     * Each item also gets, where it applies:
     *  - `actor.is_following` — whether you follow them (Follow back button)
     *  - `video.thumbnail_url` — filled in for types that don't store one
     */
    public function index(Request $request)
    {
        $request->validate([
            'filter' => 'nullable|in:all,'.implode(',', array_keys(self::FILTERS)),
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth()->user();
        $filter = $request->input('filter', 'all');

        $notifications = $user->notifications()
            ->when($filter !== 'all', fn ($q) => $q->whereIn('type', self::FILTERS[$filter]))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $items = $this->transformAll($notifications->getCollection(), $user);

        return ApiResponse::success([
            'contents' => $items,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'has_more' => $notifications->hasMorePages(),
                'filter' => $filter,
                'unread_count' => $user->unreadNotifications()->count(),
                'unread' => $this->unreadByFilter($user),
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

    /**
     * POST /notifications/read — mark several as read at once (a grouped row
     * like "liked 8 of your reviews" covers several notifications).
     */
    public function markManyRead(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'string',
        ]);

        $updated = auth()->user()->unreadNotifications()
            ->whereIn('id', $request->input('ids'))
            ->update(['read_at' => now()]);

        return ApiResponse::success(['updated' => $updated], 'Notifications marked as read.');
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

    /**
     * DELETE /notifications/batch — delete several (a grouped row).
     */
    public function destroyMany(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'string',
        ]);

        $deleted = auth()->user()->notifications()->whereIn('id', $request->input('ids'))->delete();

        return ApiResponse::success(['deleted' => $deleted], 'Notifications deleted.');
    }

    public function destroyAll()
    {
        auth()->user()->notifications()->delete();

        return ApiResponse::success(null, 'All notifications deleted.');
    }

    /**
     * Unread count per filter tab, plus `all`.
     *
     * @return array<string, int>
     */
    private function unreadByFilter($user): array
    {
        // reorder(): the relation sorts by date by default, which MySQL's
        // only_full_group_by mode rejects in a grouped count.
        $byClass = $user->unreadNotifications()
            ->reorder()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $counts = ['all' => (int) $byClass->sum()];
        foreach (self::FILTERS as $name => $classes) {
            $counts[$name] = (int) collect($classes)->sum(fn ($c) => $byClass[$c] ?? 0);
        }

        return $counts;
    }

    /**
     * Flattens each notification and fills in what the stored payload
     * lacks, with one query per extra lookup (not one per notification).
     */
    private function transformAll(Collection $notifications, $user): Collection
    {
        $items = $notifications->map(fn ($n) => [
            'id' => $n->id,
            'is_read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toJSON(),
            ...$n->data,
        ]);

        // Missing thumbnails (e.g. replies store only the video id).
        $missing = $items
            ->filter(fn ($i) => isset($i['video']['id']) && empty($i['video']['thumbnail_url']))
            ->pluck('video.id')
            ->unique();
        $thumbs = $missing->isEmpty()
            ? collect()
            : VideoReview::whereIn('id', $missing)->pluck('thumbnail_url', 'id');

        $following = array_flip($user->followingUserIds());

        return $items->map(function ($item) use ($thumbs, $following) {
            if (isset($item['video']['id']) && empty($item['video']['thumbnail_url'])) {
                $item['video']['thumbnail_url'] = $thumbs[$item['video']['id']] ?? null;
            }
            if (isset($item['actor']['id'])) {
                $item['actor']['is_following'] = isset($following[$item['actor']['id']]);
            }

            return $item;
        })->values();
    }
}
