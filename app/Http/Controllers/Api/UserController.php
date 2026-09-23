<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VideoLike;
use App\Models\VideoReview;
use App\Notifications\UserFollowed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

class UserController extends Controller
{
    /**
     * Public user profile view. Guest-accessible — only non-sensitive fields,
     * plus public stats (follower/following/post counts, total likes received).
     */
    public function show(string $username)
    {
        $user = User::where('username', $username)
            ->select(['id', 'name', 'username', 'profile_picture', 'profile_thumbnail', 'cover_picture', 'cover_thumbnail', 'bio', 'created_at'])
            ->withCount(['followers', 'following'])
            ->first();

        if (! $user) {
            return ApiResponse::error('User not found.', 404);
        }

        $user->setAttribute(
            'posts_count',
            VideoReview::where('user_id', $user->id)->where('status', 'ready')->count()
        );

        $user->setAttribute(
            'total_likes_received',
            VideoLike::whereHas('videoReview', fn ($q) => $q->where('user_id', $user->id)->where('status', 'ready'))->count()
        );

        return ApiResponse::success($user, 'User profile retrieved successfully.');
    }

    /**
     * Videos this user has posted. Public — guests and other users only see
     * `ready` videos; the profile owner also sees their own still-processing
     * or failed uploads.
     */
    public function videos(string $username, Request $request)
    {
        $target = User::where('username', $username)->first();

        if (! $target) {
            return ApiResponse::error('User not found.', 404);
        }

        $viewerId = $this->currentViewerId();
        $isOwnProfile = $viewerId && $viewerId === $target->id;

        $query = VideoReview::where('user_id', $target->id)
            ->with([
                'restaurant:id,name,profile_picture',
                'hashtags:id,name',
            ])
            ->withCount(['likes', 'comments']);

        if (! $isOwnProfile) {
            $query->where('status', 'ready');
        }

        return ApiResponse::success(
            $this->paginateVideos($query, $request, $viewerId),
            'Videos retrieved successfully.'
        );
    }

    /**
     * Videos this user has liked. Private — only the profile owner can view
     * their own liked list.
     */
    public function likedVideos(string $username, Request $request)
    {
        return $this->privateVideoList($username, $request, 'likes', 'Liked videos retrieved successfully.');
    }

    /**
     * Videos this user has saved/bookmarked. Private — only the profile
     * owner can view their own saved list.
     */
    public function savedVideos(string $username, Request $request)
    {
        return $this->privateVideoList($username, $request, 'saves', 'Saved videos retrieved successfully.');
    }

    private function privateVideoList(string $username, Request $request, string $relation, string $message)
    {
        $target = User::where('username', $username)->first();

        if (! $target) {
            return ApiResponse::error('User not found.', 404);
        }

        $viewerId = auth()->id();

        if ($viewerId !== $target->id) {
            return ApiResponse::error('You can only view your own '.$relation.' list.', 403);
        }

        $query = VideoReview::whereHas($relation, fn ($q) => $q->where('user_id', $target->id))
            ->where('status', 'ready')
            ->with([
                'user:id,name,username,profile_picture',
                'restaurant:id,name,profile_picture',
                'hashtags:id,name',
            ])
            ->withCount(['likes', 'comments']);

        return ApiResponse::success(
            $this->paginateVideos($query, $request, $viewerId),
            $message
        );
    }

    /**
     * Shared cursor pagination for video list endpoints — mirrors
     * VideoReviewController::feed()'s pagination shape so the client can
     * reuse the same parsing/infinite-scroll logic everywhere.
     */
    private function paginateVideos(Builder $query, Request $request, ?int $viewerId): array
    {
        if ($viewerId) {
            $query->withExists([
                'likes as liked_by_me' => fn ($q) => $q->where('user_id', $viewerId),
                'saves as saved_by_me' => fn ($q) => $q->where('user_id', $viewerId),
            ]);
        }

        if ($request->filled('cursor')) {
            $query->where('id', '<', $request->integer('cursor'));
        }

        $limit = $request->integer('limit', 5);

        $videos = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $videos->count() > $limit;
        $videos = $videos->take($limit);

        return [
            'contents' => $videos->values(),
            'meta' => [
                'next_cursor' => $videos->isNotEmpty() ? $videos->last()->id : null,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * Resolve the signed-in user's ID on a public route without forcing auth.
     * Returns null for guests or invalid/missing tokens instead of throwing.
     */
    private function currentViewerId(): ?int
    {
        try {
            return auth('api')->id();
        } catch (Throwable $e) {
            return null;
        }
    }

    public function follow(string $username)
    {
        $target = User::where('username', $username)->first();

        if (! $target) {
            return ApiResponse::error('User not found.', 404);
        }

        $viewer = auth()->user();

        if ($target->id === $viewer->id) {
            return ApiResponse::error('You cannot follow yourself.', 400);
        }

        $wasAlreadyFollowing = $viewer->isFollowing($target);
        $viewer->follow($target);

        if (! $wasAlreadyFollowing) {
            $target->notify(new UserFollowed($viewer));
        }

        return ApiResponse::success([
            'followers_count' => $target->followers()->count(),
        ], 'User followed successfully.');
    }

    public function unfollow(string $username)
    {
        $target = User::where('username', $username)->first();

        if (! $target) {
            return ApiResponse::error('User not found.', 404);
        }

        auth()->user()->unfollow($target);

        return ApiResponse::success([
            'followers_count' => $target->followers()->count(),
        ], 'User unfollowed successfully.');
    }
}
