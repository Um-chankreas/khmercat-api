<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

/**
 * A restaurant's Reviews tab: the rating summary, everyone's reviews (with
 * or without a video), likes, and the restaurant's replies.
 */
class RestaurantReviewController extends Controller
{
    private const PER_PAGE = 10;

    /**
     * Public. `filter=video` keeps reviews with a playable video. Cursor-
     * paginated newest first: pass `meta.next_cursor` back as `cursor`.
     * The summary and counts describe every review, whatever the filter.
     */
    public function index(Request $request, int $id)
    {
        $request->validate([
            'filter' => 'nullable|in:all,video',
            'cursor' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        $viewerId = $this->viewerId();
        $restaurant = Restaurant::find($id);
        if (! $restaurant || ! $restaurant->visibleTo($viewerId)) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $base = fn () => RestaurantReview::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('user', fn ($u) => $u->active());

        $counts = $base()->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $total = (int) $counts->sum();
        $sum = $counts->reduce(fn ($carry, $n, $star) => $carry + $n * $star, 0);

        $query = $this->withDetails($base(), $viewerId);
        if ($request->input('filter') === 'video') {
            $query->whereHas('video', fn ($v) => $v->where('status', 'ready'));
        }
        if ($request->filled('cursor')) {
            $query->where('id', '<', $request->integer('cursor'));
        }

        $limit = $request->integer('limit', self::PER_PAGE);
        $reviews = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $reviews->count() > $limit;
        $reviews = $reviews->take($limit);

        $mine = $viewerId
            ? $this->withDetails($base()->where('user_id', $viewerId), $viewerId)->first()
            : null;

        return ApiResponse::success([
            'summary' => [
                'average' => $total ? round($sum / $total, 1) : null,
                'total' => $total,
                'counts' => collect(range(1, 5))->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            ],
            'with_video_count' => $base()->whereHas('video', fn ($v) => $v->where('status', 'ready'))->count(),
            'my_review' => $mine ? $this->present($mine) : null,
            'contents' => $reviews->map(fn ($r) => $this->present($r))->values(),
            'meta' => [
                'next_cursor' => $reviews->isNotEmpty() ? $reviews->last()->id : null,
                'has_more' => $hasMore,
            ],
        ], 'Reviews retrieved successfully.');
    }

    /**
     * Rate + comment without a video (a review video goes through
     * POST /reviews/upload, which records the review too). One review per
     * person per restaurant: posting again updates it.
     */
    public function store(Request $request, int $id)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:500',
        ]);

        $restaurant = Restaurant::published()->find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }
        $user = auth()->user();
        if ($user->restaurants()->where('restaurant_id', $restaurant->id)->wherePivot('status', 'accepted')->exists()) {
            return ApiResponse::error('You cannot review a restaurant you manage.', 403);
        }

        $review = RestaurantReview::upsertFor($restaurant->id, $user->id, $request->integer('rating'), trim($request->input('comment')));

        return ApiResponse::success(
            $this->present($this->withDetails(RestaurantReview::whereKey($review->id), $user->id)->first()),
            $review->wasRecentlyCreated ? 'Review posted.' : 'Review updated.',
        );
    }

    /** The author removes their review (their video, if any, stays posted). */
    public function destroy(int $reviewId)
    {
        $review = RestaurantReview::find($reviewId);
        if (! $review) {
            return ApiResponse::error('Review not found.', 404);
        }
        if ($review->user_id !== auth()->id()) {
            return ApiResponse::error('You can only delete your own review.', 403);
        }
        $review->delete();

        return ApiResponse::success(null, 'Review deleted.');
    }

    public function like(int $reviewId)
    {
        $review = RestaurantReview::find($reviewId);
        if (! $review) {
            return ApiResponse::error('Review not found.', 404);
        }
        $review->likes()->firstOrCreate(['user_id' => auth()->id()]);

        return ApiResponse::success(['likes_count' => $review->likes()->count()], 'Review liked.');
    }

    public function unlike(int $reviewId)
    {
        $review = RestaurantReview::find($reviewId);
        if (! $review) {
            return ApiResponse::error('Review not found.', 404);
        }
        $review->likes()->where('user_id', auth()->id())->delete();

        return ApiResponse::success(['likes_count' => $review->likes()->count()], 'Review unliked.');
    }

    /** The restaurant's owner/manager answers a review (or edits the answer). */
    public function reply(Request $request, int $reviewId)
    {
        $request->validate(['text' => 'required|string|max:500']);

        $review = RestaurantReview::find($reviewId);
        if (! $review) {
            return ApiResponse::error('Review not found.', 404);
        }
        $user = auth()->user();
        if (! $user->managesRestaurant($review->restaurant_id)) {
            return ApiResponse::error('Only the restaurant can reply to its reviews.', 403);
        }

        $review->forceFill([
            'reply' => trim($request->input('text')),
            'replied_by' => $user->id,
            'replied_at' => now(),
        ])->save();

        return ApiResponse::success(
            $this->present($this->withDetails(RestaurantReview::whereKey($review->id), $user->id)->first()),
            'Reply saved.',
        );
    }

    public function deleteReply(int $reviewId)
    {
        $review = RestaurantReview::find($reviewId);
        if (! $review) {
            return ApiResponse::error('Review not found.', 404);
        }
        if (! auth()->user()->managesRestaurant($review->restaurant_id)) {
            return ApiResponse::error('Only the restaurant can remove its reply.', 403);
        }
        $review->forceFill(['reply' => null, 'replied_by' => null, 'replied_at' => null])->save();

        return ApiResponse::success(null, 'Reply removed.');
    }

    private function withDetails(Builder $query, ?int $viewerId): Builder
    {
        $query->with([
            'user:id,name,username,profile_picture',
            'video:id,status,video_url,thumbnail_url,aspect_ratio,duration_seconds',
        ])->withCount('likes');

        if ($viewerId) {
            $query->withExists(['likes as liked_by_me' => fn ($q) => $q->where('user_id', $viewerId)]);
        }

        return $query;
    }

    private function present(RestaurantReview $r): array
    {
        $video = $r->video && $r->video->status === 'ready' ? $r->video : null;

        return [
            'id' => $r->id,
            'restaurant_id' => $r->restaurant_id,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
            'likes_count' => (int) $r->likes_count,
            'liked_by_me' => (bool) ($r->liked_by_me ?? false),
            'user' => $r->user?->only(['id', 'name', 'username', 'profile_picture']),
            'video' => $video ? [
                'id' => $video->id,
                'video_url' => $video->video_url,
                'thumbnail_url' => $video->thumbnail_url,
                'aspect_ratio' => $video->aspect_ratio,
                'duration_seconds' => $video->duration_seconds,
            ] : null,
            'reply' => $r->reply ? [
                'text' => $r->reply,
                'created_at' => $r->replied_at,
            ] : null,
        ];
    }

    private function viewerId(): ?int
    {
        try {
            return auth('api')->id();
        } catch (Throwable $e) {
            return null;
        }
    }
}
