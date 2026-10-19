<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantHoliday;
use App\Models\VideoReview;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Holiday posters: a restaurant's notice for one date (closed, special hours
 * or open as usual), posted to one or several of the person's restaurants.
 */
class RestaurantHolidayController extends Controller
{
    /**
     * Posts (or, for a restaurant that already has one that day, updates)
     * the notice for each of `restaurant_ids`. Acting as a restaurant, only
     * that restaurant can be posted to. Multipart; `image` is optional.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'restaurant_ids' => 'required|array|min:1|max:50',
            'restaurant_ids.*' => 'integer|distinct',
            'date' => 'required|date_format:Y-m-d|after_or_equal:'.RestaurantHoliday::today(),
            // How many days, from `date`.
            'days' => 'nullable|integer|min:1|max:14',
            'title' => 'required|string|max:80',
            'holiday_key' => 'nullable|string|max:40',
            'mode' => ['required', Rule::in([
                RestaurantHoliday::MODE_CLOSED,
                RestaurantHoliday::MODE_SPECIAL,
                RestaurantHoliday::MODE_OPEN,
            ])],
            'opens_at' => 'required_if:mode,special|nullable|date_format:H:i',
            'closes_at' => 'required_if:mode,special|nullable|date_format:H:i',
            'description' => 'nullable|string|max:300',
            'image' => 'nullable|image|max:10240',
        ]);

        $user = auth()->user();
        $ids = collect($data['restaurant_ids'])->map(fn ($id) => (int) $id)->unique();

        if ($user->active_restaurant_id && $ids->all() !== [(int) $user->active_restaurant_id]) {
            return ApiResponse::error('Acting as a restaurant, you can only post for that restaurant.', 403);
        }
        foreach ($ids as $id) {
            if (! $user->managesRestaurant($id)) {
                return ApiResponse::error('You can only post for restaurants you own or manage.', 403);
            }
        }

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('restaurants/holidays', 'public')
            : null;
        $special = $data['mode'] === RestaurantHoliday::MODE_SPECIAL;
        $endDate = Carbon::parse($data['date'])
            ->addDays(($data['days'] ?? 1) - 1)
            ->toDateString();

        $holidays = DB::transaction(function () use ($ids, $data, $user, $imagePath, $special, $endDate) {
            return $ids->map(function ($id) use ($data, $user, $imagePath, $special, $endDate) {
                $holiday = RestaurantHoliday::firstOrNew(['restaurant_id' => $id, 'date' => $data['date']]);
                $holiday->fill([
                    'created_by' => $user->id,
                    'end_date' => $endDate,
                    'title' => trim($data['title']),
                    'holiday_key' => $data['holiday_key'] ?? null,
                    'mode' => $data['mode'],
                    'opens_at' => $special ? $data['opens_at'] : null,
                    'closes_at' => $special ? $data['closes_at'] : null,
                    'description' => isset($data['description']) ? trim($data['description']) : null,
                ]);
                // A new image replaces the old one; none keeps what it had.
                if ($imagePath) {
                    $holiday->image_path = $imagePath;
                }

                // Its feed post (an image card, no video).
                $post = $holiday->feedPost ?? new VideoReview([
                    'user_id' => $user->id,
                    'restaurant_id' => $id,
                    'type' => VideoReview::TYPE_HOLIDAY_POSTER,
                    'status' => 'ready',
                ]);
                $post->caption = $holiday->description;
                $post->thumbnail_url = $holiday->image_path
                    ? Storage::disk('public')->url($holiday->image_path)
                    : null;
                $post->save();

                $holiday->video_review_id = $post->id;
                $holiday->save();

                return $holiday->fresh();
            });
        });

        return ApiResponse::success($holidays->values(), 'Holiday poster posted.');
    }

    /** The team takes a notice down (and its feed post with it). */
    public function destroy(int $holidayId)
    {
        $holiday = RestaurantHoliday::find($holidayId);
        if (! $holiday) {
            return ApiResponse::error('Holiday not found.', 404);
        }
        if (! auth()->user()->managesRestaurant($holiday->restaurant_id)) {
            return ApiResponse::error('Only the restaurant can remove its holiday poster.', 403);
        }

        DB::transaction(function () use ($holiday) {
            $holiday->feedPost?->forceDelete();
            $holiday->delete();
        });

        // The image may be shared with the same poster on other restaurants.
        if ($holiday->image_path && ! RestaurantHoliday::where('image_path', $holiday->image_path)->exists()) {
            Storage::disk('public')->delete($holiday->image_path);
        }

        return ApiResponse::success(null, 'Holiday poster removed.');
    }

    /** Public: a restaurant's notices not yet over (its poster first). */
    public function index(int $id)
    {
        $restaurant = Restaurant::find($id);
        if (! $restaurant || ! $restaurant->visibleTo(auth('api')->check() ? auth('api')->id() : null)) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        return ApiResponse::success(
            $restaurant->holidays()->whereDate('end_date', '>=', RestaurantHoliday::today())->orderBy('date')->get(),
            'Holidays retrieved successfully.',
        );
    }
}
