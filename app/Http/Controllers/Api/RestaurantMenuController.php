<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantMenuImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A restaurant's menu as photos of its pages. Anyone can view it; owners
 * and managers add and remove pages.
 */
class RestaurantMenuController extends Controller
{
    /** Most pages a menu can have. */
    private const MAX_PAGES = 20;

    /**
     * GET /restaurants/{id}/menu — pages in order, plus the public web URL
     * the QR code encodes.
     */
    public function index(int $id)
    {
        $restaurant = Restaurant::find($id);
        $viewerId = null;
        try {
            $viewerId = auth('api')->id();
        } catch (\Throwable $e) {
        }
        if (! $restaurant || (! $restaurant->is_published && ! $restaurant->hasMember($viewerId))) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        return ApiResponse::success([
            'menu_url' => $restaurant->menuWebUrl(),
            'images' => $restaurant->menuImages()->get(),
        ], 'Menu retrieved successfully.');
    }

    /**
     * POST /restaurants/{id}/menu — multipart `images[]`, appended after the
     * existing pages.
     */
    public function store(int $id, Request $request)
    {
        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }
        if (! auth()->user()->managesRestaurant($id)) {
            return ApiResponse::error('Only the restaurant\'s owner or managers can edit its menu.', 403);
        }

        $existing = $restaurant->menuImages()->count();
        $request->validate([
            'images' => 'required|array|min:1|max:'.max(1, self::MAX_PAGES - $existing),
            'images.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192', // 8MB each
        ], [
            'images.max' => 'A menu can have up to '.self::MAX_PAGES.' pages.',
        ]);
        if ($existing >= self::MAX_PAGES) {
            return ApiResponse::validationError(
                ['images' => ['A menu can have up to '.self::MAX_PAGES.' pages.']],
                'Menu is full.'
            );
        }

        $position = (int) $restaurant->menuImages()->max('position');
        foreach ($request->file('images') as $file) {
            $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
            $path = $file->storeAs("restaurants/{$id}/menu", Str::uuid().".{$extension}", 'public');
            $restaurant->menuImages()->create([
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'position' => ++$position,
            ]);
        }

        return $this->index($id);
    }

    /**
     * DELETE /restaurants/{id}/menu/{imageId} — removes one page (and its file).
     */
    public function destroy(int $id, int $imageId)
    {
        if (! Restaurant::whereKey($id)->exists()) {
            return ApiResponse::error('Restaurant not found.', 404);
        }
        if (! auth()->user()->managesRestaurant($id)) {
            return ApiResponse::error('Only the restaurant\'s owner or managers can edit its menu.', 403);
        }

        $image = RestaurantMenuImage::where('restaurant_id', $id)->find($imageId);
        if (! $image) {
            return ApiResponse::error('Menu page not found.', 404);
        }

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return $this->index($id);
    }
}
