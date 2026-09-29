<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

class SearchController extends Controller
{
    /** Candidates fetched before ranking; the lists returned are capped below. */
    private const RESTAURANT_POOL = 60;

    private const RESTAURANT_LIMIT = 30;

    private const RECOMMENDED_LIMIT = 10;

    private const VIDEO_LIMIT = 30;

    private const USER_LIMIT = 30;

    /**
     * Search restaurants, videos and people. Guest-accessible.
     *
     * Query params:
     *  - q            keyword (optional when category_id is given)
     *  - category_id  only restaurants in this cuisine (and videos about them)
     *  - lat, lng     viewer position: adds `distance_km` to each restaurant
     *  - sort         relevance (default) | nearest
     *
     * Response: restaurants, recommended, videos, users, plus `counts` — the
     * total number of matches per section, which can exceed the list sizes.
     */
    public function index(Request $request)
    {
        $request->validate([
            'q' => 'nullable|required_without:category_id|string|max:100',
            'category_id' => 'nullable|integer|exists:restaurant_categories,id',
            'lat' => 'nullable|required_with:lng|numeric|between:-90,90',
            'lng' => 'nullable|required_with:lat|numeric|between:-180,180',
            'sort' => 'nullable|in:relevance,nearest',
        ]);

        $q = trim((string) $request->input('q', ''));
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;
        $hasLocation = $request->filled('lat') && $request->filled('lng');
        $lat = $hasLocation ? $request->float('lat') : null;
        $lng = $hasLocation ? $request->float('lng') : null;
        $sort = $request->input('sort', 'relevance');

        $viewer = $this->currentViewer();

        [$restaurants, $recommended, $restaurantTotal] = $this->searchRestaurants($q, $categoryId, $lat, $lng, $sort, $viewer);
        [$videos, $videoTotal] = $this->searchVideos($q, $categoryId, $viewer);
        [$users, $userTotal] = $this->searchUsers($q, $viewer);

        return ApiResponse::success([
            'restaurants' => $restaurants,
            'recommended' => $recommended,
            'videos' => $videos,
            'users' => $users,
            'counts' => [
                'restaurants' => $restaurantTotal,
                'videos' => $videoTotal,
                'users' => $userTotal,
            ],
        ], 'Search results retrieved successfully.');
    }

    /**
     * @return array{0: Collection, 1: Collection, 2: int}
     */
    private function searchRestaurants(string $q, ?int $categoryId, ?float $lat, ?float $lng, string $sort, ?User $viewer): array
    {
        $query = Restaurant::query()
            ->published()
            ->when($q !== '', fn (Builder $query) => $query->where(function (Builder $w) use ($q) {
                $like = '%'.$q.'%';
                $w->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', $like));
            }))
            ->when($categoryId, fn (Builder $query) => $query->where('category_id', $categoryId));

        $total = (clone $query)->count();

        $readyReviews = fn ($r) => $r->where('status', 'ready');

        $pool = $query
            ->with('category:id,name,icon')
            ->withCount(['followers', 'reviews as reviews_count' => $readyReviews])
            ->withAvg(['reviews as avg_rating' => fn ($r) => $r->where('status', 'ready')->whereNotNull('rating')], 'rating')
            ->limit(self::RESTAURANT_POOL)
            ->get();

        $followedIds = $viewer ? $viewer->followingRestaurantIds() : [];
        $needle = mb_strtolower($q);

        $pool->each(function (Restaurant $r) use ($lat, $lng, $followedIds, $needle) {
            $distance = $lat !== null ? $r->distanceKmTo($lat, $lng) : null;
            $r->setAttribute('distance_km', $distance !== null ? round($distance, 2) : null);
            $r->setAttribute('avg_rating', $r->avg_rating !== null ? round((float) $r->avg_rating, 1) : null);
            $r->setAttribute('is_open', $r->isOpenNow());
            $r->setAttribute('is_following', in_array($r->id, $followedIds));

            // 0 = name starts with the term, 1 = name contains it, 2 = other field matched.
            $name = mb_strtolower($r->name);
            $r->relevance = $needle === '' ? 2 : (str_starts_with($name, $needle) ? 0 : (str_contains($name, $needle) ? 1 : 2));
        });

        $byDistance = fn (Restaurant $r) => $r->distance_km ?? PHP_FLOAT_MAX;

        $restaurants = ($sort === 'nearest' && $lat !== null)
            ? $pool->sortBy([
                fn ($a, $b) => $byDistance($a) <=> $byDistance($b),
                fn ($a, $b) => $b->reviews_count <=> $a->reviews_count,
            ])
            : $pool->sortBy([
                fn ($a, $b) => $a->relevance <=> $b->relevance,
                fn ($a, $b) => ($b->avg_rating ?? 0) <=> ($a->avg_rating ?? 0),
                fn ($a, $b) => $b->reviews_count <=> $a->reviews_count,
            ]);

        // Recommended: sponsored first, then the best-rated / most-reviewed
        // matches. Only restaurants with something to recommend them.
        $recommended = $pool
            ->filter(fn (Restaurant $r) => $r->is_sponsored || $r->avg_rating !== null)
            ->sortBy([
                fn ($a, $b) => $b->is_sponsored <=> $a->is_sponsored,
                fn ($a, $b) => ($b->avg_rating ?? 0) <=> ($a->avg_rating ?? 0),
                fn ($a, $b) => $b->reviews_count <=> $a->reviews_count,
            ]);

        $clean = fn (Collection $list, int $limit) => $list
            ->take($limit)
            ->each(fn (Restaurant $r) => $r->offsetUnset('relevance'))
            ->values();

        return [
            $clean($restaurants, self::RESTAURANT_LIMIT),
            $clean($recommended, self::RECOMMENDED_LIMIT),
            $total,
        ];
    }

    /**
     * @return array{0: Collection, 1: int}
     */
    private function searchVideos(string $q, ?int $categoryId, ?User $viewer): array
    {
        $tag = mb_strtolower(ltrim($q, '#'));

        $query = VideoReview::where('status', 'ready')
            // Not from restaurants that are unpublished or deleted.
            ->whereHas('restaurant', fn (Builder $r) => $r->published())
            // Not from deactivated or deleted accounts.
            ->whereHas('user', fn (Builder $u) => $u->active())
            ->when($q !== '', fn (Builder $query) => $query->where(function (Builder $w) use ($q, $tag) {
                $w->where('caption', 'like', '%'.$q.'%')
                    ->orWhereHas('hashtags', fn (Builder $h) => $h->where('name', $tag))
                    ->orWhereHas('restaurant', fn (Builder $r) => $r->where('name', 'like', '%'.$q.'%'));
            }))
            ->when($categoryId, fn (Builder $query) => $query->whereHas(
                'restaurant',
                fn (Builder $r) => $r->where('category_id', $categoryId)
            ));

        $total = (clone $query)->count();

        $videos = $query
            ->with([
                'user:id,name,username,profile_picture,is_verified',
                'restaurant:id,name,profile_picture',
                'hashtags:id,name',
            ])
            ->withCount(['likes', 'comments'])
            ->when($viewer, fn (Builder $query) => $query->withExists([
                'likes as liked_by_me' => fn ($l) => $l->where('user_id', $viewer->id),
                'saves as saved_by_me' => fn ($s) => $s->where('user_id', $viewer->id),
            ]))
            ->orderByDesc('views_count')
            ->orderByDesc('likes_count')
            ->orderByDesc('id')
            ->limit(self::VIDEO_LIMIT)
            ->get();

        $followedIds = $viewer ? $viewer->followingRestaurantIds() : [];
        $videos->each(fn (VideoReview $v) => $v->setAttribute(
            'is_following_restaurant',
            $v->restaurant_id !== null && in_array($v->restaurant_id, $followedIds)
        ));

        return [$videos, $total];
    }

    /**
     * People whose name or username matches, most-followed first.
     *
     * @return array{0: Collection, 1: int}
     */
    private function searchUsers(string $q, ?User $viewer): array
    {
        if ($q === '') {
            return [collect(), 0];
        }

        $handle = ltrim($q, '@');
        $query = User::active()->where(fn (Builder $w) => $w
            ->where('name', 'like', '%'.$q.'%')
            ->orWhere('username', 'like', '%'.$handle.'%'));

        $total = (clone $query)->count();

        $users = $query
            ->select(['id', 'name', 'username', 'profile_picture', 'bio', 'is_verified'])
            ->withCount([
                'followers',
                'videoReviews as reviews_count' => fn ($v) => $v
                    ->where('type', VideoReview::TYPE_REVIEW)
                    ->where('status', 'ready'),
            ])
            ->orderByDesc('followers_count')
            ->orderByDesc('reviews_count')
            ->limit(self::USER_LIMIT)
            ->get();

        $followedIds = $viewer ? $viewer->followingUserIds() : [];
        $users->each(fn (User $u) => $u->setAttribute('is_following', in_array($u->id, $followedIds)));

        return [$users, $total];
    }

    /**
     * The signed-in user, or null for guests (or an invalid/expired token —
     * this route is public, so a bad token just means "guest").
     */
    private function currentViewer(): ?User
    {
        try {
            return auth('api')->user();
        } catch (Throwable $e) {
            return null;
        }
    }
}
