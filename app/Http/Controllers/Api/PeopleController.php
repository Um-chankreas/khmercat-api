<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Lists of people behind a profile's stats: a restaurant's followers and
 * reviewers, a user's followers and who they follow. Public; for a signed-in
 * viewer each person also carries `is_following` (does the viewer follow
 * them) and `is_me`, so the app can show a Follow button.
 *
 * Paged with `?page=` (30 a page); the response says whether there's more.
 */
class PeopleController extends Controller
{
    private const PER_PAGE = 30;

    private const USER_COLUMNS = ['id', 'name', 'username', 'profile_picture', 'profile_thumbnail', 'is_verified'];

    public function restaurantFollowers(Request $request, int $id)
    {
        $restaurant = $this->visibleRestaurant($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        return $this->followersOf($request, $restaurant);
    }

    /**
     * Everyone with a published review of the restaurant, most recent
     * reviewer first, with how many reviews each has posted there.
     */
    public function restaurantReviewers(Request $request, int $id)
    {
        $restaurant = $this->visibleRestaurant($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $page = max(1, $request->integer('page', 1));
        $rows = VideoReview::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('type', VideoReview::TYPE_REVIEW)
            ->where('status', 'ready')
            ->whereHas('user', fn ($q) => $q->active())
            ->selectRaw('user_id, count(*) as reviews_count, max(id) as last_review_id')
            ->groupBy('user_id')
            ->orderByDesc('last_review_id')
            ->forPage($page, self::PER_PAGE + 1)
            ->get();

        $hasMore = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);
        $users = User::whereIn('id', $rows->pluck('user_id'))->get(self::USER_COLUMNS)->keyBy('id');

        $people = $rows
            ->map(function ($row) use ($users) {
                $user = $users->get($row->user_id);
                $user?->setAttribute('reviews_count', (int) $row->reviews_count);

                return $user;
            })
            ->filter()
            ->values();

        return $this->respond($people, $page, $hasMore);
    }

    /**
     * The people this restaurant follows (follows its team made while
     * acting as it), newest first.
     */
    public function restaurantFollowing(Request $request, int $id)
    {
        $restaurant = $this->visibleRestaurant($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        return $this->followedUsers($request, $restaurant->following());
    }

    public function userFollowers(Request $request, string $username)
    {
        $user = User::active()->where('username', $username)->first();
        if (! $user) {
            return ApiResponse::error('User not found.', 404);
        }

        return $this->followersOf($request, $user);
    }

    /**
     * The people (not restaurants) this user follows personally, newest
     * first.
     */
    public function userFollowing(Request $request, string $username)
    {
        $user = User::active()->where('username', $username)->first();
        if (! $user) {
            return ApiResponse::error('User not found.', 404);
        }

        return $this->followedUsers($request, $user->personalFollowing());
    }

    /**
     * The users among the follows in [$follows], newest first.
     */
    private function followedUsers(Request $request, Relation $follows)
    {
        $page = max(1, $request->integer('page', 1));
        $rows = $follows
            ->where('followable_type', (new User)->getMorphClass())
            ->whereIn('followable_id', User::active()->select('id'))
            ->latest('id')
            ->forPage($page, self::PER_PAGE + 1)
            ->get(['followable_id']);

        $hasMore = $rows->count() > self::PER_PAGE;
        $ids = $rows->take(self::PER_PAGE)->pluck('followable_id');

        return $this->respond($this->usersInOrder($ids), $page, $hasMore);
    }

    /**
     * The people following [$followable] (a user or a restaurant), newest
     * first.
     */
    private function followersOf(Request $request, User|Restaurant $followable)
    {
        $page = max(1, $request->integer('page', 1));
        $follows = $followable->followers()
            ->whereHas('follower', fn (Builder $q) => $q->active())
            ->latest('id')
            ->forPage($page, self::PER_PAGE + 1)
            ->get(['follower_id']);

        $hasMore = $follows->count() > self::PER_PAGE;
        $ids = $follows->take(self::PER_PAGE)->pluck('follower_id');

        return $this->respond($this->usersInOrder($ids), $page, $hasMore);
    }

    /** The users with [$ids], in that order. */
    private function usersInOrder(Collection $ids): Collection
    {
        $users = User::whereIn('id', $ids)->get(self::USER_COLUMNS)->keyBy('id');

        return $ids->map(fn ($id) => $users->get($id))->filter()->values();
    }

    /**
     * Adds the viewer's follow state to each person and wraps the page.
     */
    private function respond(Collection $people, int $page, bool $hasMore)
    {
        $viewerId = $this->viewerId();
        // As whichever profile the viewer is acting as.
        $followedIds = $viewerId
            ? User::find($viewerId)
                ->actorFollowing()
                ->where('followable_type', (new User)->getMorphClass())
                ->whereIn('followable_id', $people->pluck('id'))
                ->pluck('followable_id')
                ->all()
            : [];

        $people->each(function (User $person) use ($viewerId, $followedIds) {
            $person->setAttribute('is_me', $person->id === $viewerId);
            $person->setAttribute('is_following', in_array($person->id, $followedIds, true));
        });

        return ApiResponse::success([
            'people' => $people,
            'page' => $page,
            'has_more' => $hasMore,
        ]);
    }

    /** Restaurants that aren't public yet: only their team and admins. */
    private function visibleRestaurant(int $id): ?Restaurant
    {
        $restaurant = Restaurant::find($id);
        if (! $restaurant || ! $restaurant->visibleTo($this->viewerId())) {
            return null;
        }

        return $restaurant;
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
