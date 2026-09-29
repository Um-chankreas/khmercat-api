<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\VideoReview;
use Illuminate\Http\Request;

/**
 * A restaurant's own posts, managed by its team (owners and managers): the
 * restaurant-side counterpart of the personal profile's Delete tab. Deleted
 * posts are soft-deleted and purged after 30 days by `videos:prune-trashed`.
 * Customers' reviews of the restaurant aren't the restaurant's to delete.
 */
class RestaurantVideoController extends Controller
{
    /**
     * DELETE /restaurants/{id}/videos/{videoId}
     */
    public function destroy(int $id, int $videoId)
    {
        if (! auth()->user()->managesRestaurant($id)) {
            return ApiResponse::error('Only this restaurant\'s team can delete its videos.', 403);
        }

        $video = VideoReview::where('restaurant_id', $id)
            ->where('type', VideoReview::TYPE_RESTAURANT_POST)
            ->find($videoId);

        if (! $video) {
            return ApiResponse::error('Video not found.', 404);
        }

        $video->delete();

        return ApiResponse::success(null, 'Video deleted.');
    }

    /**
     * GET /restaurants/{id}/videos/deleted — newest first, keyset-paginated
     * by `cursor` in the same shape as the feed, so the app parses it the
     * same way.
     */
    public function deleted(int $id, Request $request)
    {
        $request->validate([
            'cursor' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        if (! auth()->user()->managesRestaurant($id)) {
            return ApiResponse::error('Only this restaurant\'s team can see its deleted videos.', 403);
        }

        $limit = $request->integer('limit', 12);
        $videos = VideoReview::onlyTrashed()
            ->where('restaurant_id', $id)
            ->where('type', VideoReview::TYPE_RESTAURANT_POST)
            ->when($request->filled('cursor'), fn ($q) => $q->where('id', '<', $request->integer('cursor')))
            ->with([
                'user:id,name,username,profile_picture',
                'restaurant:id,name,profile_picture',
            ])
            ->withCount(['likes', 'comments'])
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $videos->count() > $limit;
        $videos = $videos->take($limit)->values();

        return ApiResponse::success([
            'contents' => $videos,
            'meta' => [
                'next_cursor' => $videos->isNotEmpty() ? $videos->last()->id : null,
                'has_more' => $hasMore,
            ],
        ], 'Deleted videos retrieved successfully.');
    }
}
