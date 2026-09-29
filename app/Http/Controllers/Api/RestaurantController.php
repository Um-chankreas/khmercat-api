<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRestaurantRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RestaurantController extends Controller
{
    /**
     * Public restaurant profile view. Guest-accessible.
     */
    public function show(int $id)
    {
        // Review stats count only published (ready) review videos, same as search.
        $readyReviews = fn ($q) => $q->where('status', 'ready');
        $restaurant = Restaurant::with('category')
            ->withCount([
                'followers',
                'reviews as reviews_count' => $readyReviews,
                // The restaurant's own posts, for the "Posts" stat.
                'videos as videos_count' => $readyReviews,
                'menuImages as menu_images_count',
            ])
            ->withAvg(['reviews as avg_rating' => fn ($q) => $q->where('status', 'ready')->whereNotNull('rating')], 'rating')
            ->find($id);

        // Unpublished: only its team can open it; everyone else sees it gone.
        if (! $restaurant || (! $restaurant->is_published && ! $restaurant->hasMember($this->viewerId()))) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $restaurant->setAttribute('avg_rating', $restaurant->avg_rating !== null ? round((float) $restaurant->avg_rating, 1) : null);
        $restaurant->setAttribute('is_open', $restaurant->isOpenNow());
        $restaurant->setAttribute('menu_url', $restaurant->menuWebUrl());

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
                    'price_level' => $request->price_level,
                    'service_type' => $request->service_type,
                    'delivery_time_min' => $request->delivery_time_min,
                    'delivery_time_max' => $request->delivery_time_max,
                    'opening_time' => $request->opening_time,
                    'closing_time' => $request->closing_time,
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
            // Shown on the profile switcher cards.
            ->withCount(['followers', 'reviews as reviews_count' => fn ($q) => $q->where('status', 'ready')])
            ->get();

        // A restaurant the user has since left (or that was deleted) is no
        // longer a valid context — report personal instead of a stale id.
        $activeId = $restaurants->contains('id', $user->active_restaurant_id)
            ? $user->active_restaurant_id
            : null;

        return ApiResponse::success([
            'active_restaurant_id' => $activeId,
            'restaurants' => $restaurants,
        ], 'Restaurants retrieved successfully.');
    }

    /**
     * Switch the authenticated user's active context: a restaurant they're
     * an accepted member of, or back to their personal profile when
     * `restaurant_id` is null. Going back to personal requires the account
     * password (like Facebook's switch back from a Page), so someone handed
     * a phone logged in as a restaurant can't get into the owner's account.
     */
    public function switch(Request $request)
    {
        $request->validate([
            'restaurant_id' => 'present|nullable|integer|exists:restaurants,id',
        ]);

        $user = auth()->user();

        if (! $request->filled('restaurant_id')) {
            $request->validate(['password' => 'required|string']);

            if (! Hash::check($request->input('password'), $user->password)) {
                return ApiResponse::validationError(
                    ['password' => ['Incorrect password.']],
                    'Incorrect password.'
                );
            }

            $user->update(['active_restaurant_id' => null]);

            return ApiResponse::success([
                'active_restaurant_id' => null,
            ], 'Switched to your personal profile.');
        }

        if (! $user->switchToRestaurant($request->integer('restaurant_id'))) {
            return ApiResponse::error('You are not a member of this restaurant.', 403);
        }

        return ApiResponse::success([
            'active_restaurant_id' => $user->fresh()->active_restaurant_id,
        ], 'Switched active restaurant successfully.');
    }

    /**
     * Edit a restaurant's details. Owners and managers only. Every field is
     * optional (`sometimes`), so the app can send just what changed.
     */
    public function update(int $id, Request $request)
    {
        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }
        if (! auth()->user()->managesRestaurant($id)) {
            return ApiResponse::error('Only the restaurant\'s owner or managers can edit it.', 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'category_id' => 'sometimes|required|integer|exists:restaurant_categories,id',
            'address' => 'sometimes|nullable|string|max:500',
            'phone' => 'sometimes|nullable|string|max:30',
            // Same rules as a user's social links (ProfileController).
            'facebook_url' => 'sometimes|nullable|url|max:255',
            'tiktok_url' => ['sometimes', 'nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value !== null && ! preg_match('/^(https?:\/\/\S+|@?[\w.]{2,24})$/', $value)) {
                    $fail('The TikTok link must be a valid URL or username.');
                }
            }],
            'telegram_username' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]{4,32}$/'],
            'price_level' => 'sometimes|nullable|integer|between:1,4',
            'service_type' => 'sometimes|nullable|in:'.implode(',', Restaurant::SERVICE_TYPES),
            'delivery_time_min' => 'sometimes|nullable|integer|min:1|max:600',
            'delivery_time_max' => 'sometimes|nullable|integer|min:1|max:600',
            'opening_time' => 'sometimes|nullable|date_format:H:i',
            'closing_time' => 'sometimes|nullable|date_format:H:i',
            // Hide from / show to the public.
            'is_published' => 'sometimes|boolean',
        ]);

        $restaurant->update($data);

        return $this->show($id);
    }

    /**
     * Replace the restaurant's logo. Owners and managers only.
     */
    public function uploadAvatar(int $id, Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120', // 5MB
        ]);

        return $this->replaceImage($id, $request->file('avatar'), 'avatar', 'profile_picture');
    }

    /**
     * Replace the restaurant's cover photo. Owners and managers only.
     */
    public function uploadCover(int $id, Request $request)
    {
        $request->validate([
            'cover' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120', // 5MB
        ]);

        return $this->replaceImage($id, $request->file('cover'), 'cover', 'cover_picture');
    }

    /**
     * Stores the file as `restaurants/{id}/{baseName}.{ext}` (removing the
     * previous one, whatever its format) and saves the URL. A `?v=` stamp
     * makes the URL change on every upload, so the app never shows a cached
     * old picture.
     */
    private function replaceImage(int $id, UploadedFile $file, string $baseName, string $column)
    {
        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }
        if (! auth()->user()->managesRestaurant($id)) {
            return ApiResponse::error('Only the restaurant\'s owner or managers can change its photos.', 403);
        }

        $directory = "restaurants/{$id}";
        foreach (Storage::disk('public')->files($directory) as $existing) {
            if (pathinfo($existing, PATHINFO_FILENAME) === $baseName) {
                Storage::disk('public')->delete($existing);
            }
        }

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        $filename = "{$baseName}.{$extension}";
        Storage::disk('public')->putFileAs($directory, $file, $filename);
        $url = Storage::disk('public')->url("{$directory}/{$filename}").'?v='.time();

        $restaurant->update([$column => $url]);

        return ApiResponse::success([$column => $url], 'Photo updated successfully.');
    }

    /**
     * DELETE /restaurants/{id} — the owner (not managers) deletes the
     * restaurant after confirming their password. It's soft-deleted, so it
     * disappears everywhere (search, feed, profile, menu page); anyone who
     * had it as their active profile is switched back to personal.
     */
    public function destroy(int $id, Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $user = auth()->user();
        if (! $user->isRestaurantOwner($id)) {
            return ApiResponse::error('Only the restaurant\'s owner can delete it.', 403);
        }
        if (! Hash::check($request->input('password'), $user->password)) {
            return ApiResponse::validationError(
                ['password' => ['Incorrect password.']],
                'Incorrect password.'
            );
        }

        DB::transaction(function () use ($restaurant) {
            User::where('active_restaurant_id', $restaurant->id)->update(['active_restaurant_id' => null]);
            $restaurant->delete();
        });

        return ApiResponse::success(null, 'Restaurant deleted.');
    }

    /**
     * The signed-in user's id on a public route, or null for guests.
     */
    private function viewerId(): ?int
    {
        try {
            return auth('api')->id();
        } catch (Throwable $e) {
            return null;
        }
    }
}
