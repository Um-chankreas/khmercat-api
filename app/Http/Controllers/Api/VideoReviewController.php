<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessRestaurantVideo;
use App\Jobs\SendPushNotificationJob;
use App\Models\VideoReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class VideoReviewController extends Controller
{
    /**
     * TikTok-style feed. Two tabs — `for_you` (public discovery) and
     * `following` (content from users/restaurants the viewer follows).
     * Cursor-paginated: pass the last video's `id` back as `cursor` to
     * fetch the next older batch (5 at a time by default).
     */
    public function feed(Request $request)
    {
        $request->validate([
            'tab' => 'nullable|in:for_you,following',
            'cursor' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:20',
            'lat' => 'nullable|required_with:lng|numeric|between:-90,90',
            'lng' => 'nullable|required_with:lat|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:200',
            'hashtag' => 'nullable|string|max:100',
        ]);

        $tab = $request->input('tab', 'for_you');
        $viewerId = $this->currentViewerId();

        if ($tab === 'following' && ! $viewerId) {
            return ApiResponse::error('Sign in to see your following feed.', 401);
        }

        $query = VideoReview::with([
            'user:id,name,username,profile_picture',
            'restaurant:id,name,profile_picture',
            'hashtags:id,name',
        ])
            ->withCount(['likes', 'comments'])
            ->where('status', 'ready');

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', $request->integer('restaurant_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('hashtag')) {
            $tag = mb_strtolower(ltrim($request->input('hashtag'), '#'));
            $query->whereHas('hashtags', fn ($q) => $q->where('name', $tag));
        }

        // New users have no likes/follows to personalize on, so `for_you`
        // never depends on either — it's globally open. When the client can
        // get GPS, narrow it to nearby restaurants; otherwise it's just the
        // latest uploads, same as today.
        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = $request->float('lat');
            $lng = $request->float('lng');
            $radiusKm = $request->integer('radius_km', 50);

            $query->whereHas('restaurant', function ($q) use ($lat, $lng, $radiusKm) {
                $q->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereRaw(
                        '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?',
                        [$lat, $lng, $lat, $radiusKm]
                    );
            });
        }

        if ($tab === 'following') {
            $viewer = auth('api')->user();
            $followedUserIds = $viewer->followingUserIds();
            $followedRestaurantIds = $viewer->followingRestaurantIds();

            $query->where(function ($q) use ($followedUserIds, $followedRestaurantIds) {
                $q->whereIn('user_id', $followedUserIds)
                    ->orWhereIn('restaurant_id', $followedRestaurantIds);
            });
        }

        // This route is public, but still show each video's like/save state
        // for whoever's currently signed in (guests just won't have a token).
        if ($viewerId) {
            $query->withExists([
                'likes as liked_by_me' => fn ($q) => $q->where('user_id', $viewerId),
                'saves as saved_by_me' => fn ($q) => $q->where('user_id', $viewerId),
            ]);
        }

        // Keyset pagination: the mobile app passes the last video id it
        // received as `cursor` to fetch the next older batch.
        if ($request->filled('cursor')) {
            $query->where('id', '<', $request->integer('cursor'));
        }

        $limit = $request->integer('limit', 5);

        // Fetch one extra row to know whether more pages exist without a
        // separate count query.
        $videos = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $videos->count() > $limit;
        $videos = $videos->take($limit);

        $responseData = [
            'contents' => $videos->values(),
            'meta' => [
                'next_cursor' => $videos->isNotEmpty() ? $videos->last()->id : null,
                'has_more' => $hasMore,
            ],
        ];

        return ApiResponse::success($responseData, 'Video feed retrieved successfully.');
    }

    /**
     * A normal user uploads a review video for a restaurant they select.
     */
    public function uploadReview(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm,video/3gpp,video/x-matroska|max:102400',
            'caption' => 'nullable|string|max:500',
            'hashtags' => 'nullable|string|max:500',
            'restaurant_id' => 'required|integer|exists:restaurants,id',
            'rating' => 'required|integer|min:1|max:5',
        ]);

        return $this->handleUpload($request, $request->integer('restaurant_id'), VideoReview::TYPE_REVIEW, $request->integer('rating'));
    }

    /**
     * A restaurant owner/manager uploads a video on behalf of their restaurant.
     * Defaults to the user's currently active (switched-to) restaurant.
     */
    public function uploadRestaurantVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm,video/3gpp,video/x-matroska|max:102400',
            'caption' => 'nullable|string|max:500',
            'hashtags' => 'nullable|string|max:500',
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
        ]);

        $user = auth()->user();
        $restaurantId = $request->integer('restaurant_id') ?: $user->active_restaurant_id;

        if (! $restaurantId) {
            return ApiResponse::error('No active restaurant selected. Switch to a restaurant first.', 400);
        }

        if (! $user->managesRestaurant($restaurantId)) {
            return ApiResponse::error('You do not have permission to post videos for this restaurant.', 403);
        }

        return $this->handleUpload($request, $restaurantId, VideoReview::TYPE_RESTAURANT_POST);
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

    public function like(VideoReview $video)
    {
        $like = $video->likes()->firstOrCreate(['user_id' => auth()->id()]);

        // Only notify on an actual new like — not every time this endpoint
        // is hit on an already-liked video — and never for liking your own.
        if ($like->wasRecentlyCreated && $video->user_id !== auth()->id()) {
            SendPushNotificationJob::dispatch(
                $video->user,
                'New like',
                auth()->user()->name.' liked your video.',
                ['type' => 'video_liked', 'video_id' => (string) $video->id]
            );
        }

        return ApiResponse::success([
            'likes_count' => $video->likes()->count(),
        ], 'Video liked.');
    }

    public function unlike(VideoReview $video)
    {
        $video->likes()->where('user_id', auth()->id())->delete();

        return ApiResponse::success([
            'likes_count' => $video->likes()->count(),
        ], 'Video unliked.');
    }

    public function save(VideoReview $video)
    {
        $video->saves()->firstOrCreate(['user_id' => auth()->id()]);

        return ApiResponse::success(null, 'Video saved.');
    }

    public function unsave(VideoReview $video)
    {
        $video->saves()->where('user_id', auth()->id())->delete();

        return ApiResponse::success(null, 'Video removed from saved.');
    }

    private function handleUpload(Request $request, int $restaurantId, string $type, ?int $rating = null)
    {
        try {
            $tempPath = $request->file('video')->store('reviews/temp', 'local');

            $review = VideoReview::create([
                'user_id' => auth()->id(),
                'restaurant_id' => $restaurantId,
                'type' => $type,
                'caption' => $request->caption,
                'rating' => $rating,
                'status' => 'processing',
            ]);

            $review->syncHashtags($request->input('hashtags'));

            ProcessRestaurantVideo::dispatch($review, $tempPath);

            $review = $review->fresh([
                'user:id,name,username,profile_picture',
                'restaurant:id,name,profile_picture',
                'hashtags:id,name',
            ])->loadCount(['likes', 'comments']);
            $review->setAttribute('liked_by_me', false);
            $review->setAttribute('saved_by_me', false);

            return ApiResponse::success($review, 'Video uploaded successfully and processing in background.', 200);

        } catch (Throwable $e) {
            return ApiResponse::error('Upload failed.', 500, $e->getMessage());
        }
    }

    public function streamVideo($filename): BinaryFileResponse
    {
        $path = 'reviews/videos/'.$filename;
        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($path), [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => 'video/mp4',
        ]);
    }
}
