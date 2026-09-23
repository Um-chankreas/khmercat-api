<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRestaurantRequest;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RestaurantController extends Controller
{
    /**
     * Public restaurant profile view. Guest-accessible.
     */
    public function show(int $id)
    {
        $restaurant = Restaurant::with('category')->withCount('followers')->find($id);

        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        return ApiResponse::success($restaurant, 'Restaurant profile retrieved successfully.');
    }

    public function follow(int $id)
    {
        $restaurant = Restaurant::find($id);

        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        auth()->user()->follow($restaurant);

        return ApiResponse::success([
            'followers_count' => $restaurant->followers()->count(),
        ], 'Restaurant followed successfully.');
    }

    public function unfollow(int $id)
    {
        $restaurant = Restaurant::find($id);

        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        auth()->user()->unfollow($restaurant);

        return ApiResponse::success([
            'followers_count' => $restaurant->followers()->count(),
        ], 'Restaurant unfollowed successfully.');
    }

    /**
     * Create a new restaurant owned by the authenticated user.
     * Users may own multiple restaurants and switch between them.
     */
    public function create(StoreRestaurantRequest $request)
    {
        try {
            $user = auth()->user();

            return DB::transaction(function () use ($request, $user) {
                // 1. Handle image uploads
                $profilePath = null;
                if ($request->hasFile('profile_picture') && $request->file('profile_picture')->isValid()) {
                    $profilePath = $request->file('profile_picture')->store('restaurants/profiles', 'public');
                }

                $coverPath = null;
                if ($request->hasFile('cover_picture') && $request->file('cover_picture')->isValid()) {
                    $coverPath = $request->file('cover_picture')->store('restaurants/covers', 'public');
                }

                // 2. Create Restaurant Record
                $restaurant = Restaurant::create([
                    'category_id' => $request->category_id,
                    'name' => $request->name,
                    'description' => $request->description,
                    'address' => $request->address,
                    'profile_picture' => $profilePath ? Storage::disk('public')->url($profilePath) : null,
                    'cover_picture' => $coverPath ? Storage::disk('public')->url($coverPath) : null,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'status' => 'pending',
                    'approved_by' => null,
                ]);

                // 3. Attach creator as Owner in pivot table (restaurant_user)
                $restaurant->users()->attach($user->id, [
                    'role' => 'owner',
                    'status' => 'accepted',
                ]);

                // 4. Switch the user's active context to the restaurant they just created
                $user->update(['active_restaurant_id' => $restaurant->id]);

                return ApiResponse::success([
                    'user' => $user->fresh(),
                    'restaurant' => $restaurant->load('category'),
                ], 'Restaurant profile completed successfully!', 201);
            });

        } catch (Throwable $e) {
            return ApiResponse::error(
                'Failed to complete restaurant profile',
                500,
                $e->getMessage()
            );
        }
    }

    /**
     * List every restaurant the authenticated user is a member of, for the
     * mobile app's Page-switcher UI.
     */
    public function mine()
    {
        $user = auth()->user();

        $restaurants = $user->restaurants()
            ->wherePivot('status', 'accepted')
            ->with('category')
            ->get();

        return ApiResponse::success([
            'active_restaurant_id' => $user->active_restaurant_id,
            'restaurants' => $restaurants,
        ], 'Restaurants retrieved successfully.');
    }

    /**
     * Switch the authenticated user's active restaurant context.
     */
    public function switch(Request $request)
    {
        $request->validate([
            'restaurant_id' => 'required|integer|exists:restaurants,id',
        ]);

        $user = auth()->user();

        if (! $user->switchToRestaurant($request->integer('restaurant_id'))) {
            return ApiResponse::error('You are not a member of this restaurant.', 403);
        }

        return ApiResponse::success([
            'active_restaurant_id' => $user->fresh()->active_restaurant_id,
        ], 'Switched active restaurant successfully.');
    }
}
