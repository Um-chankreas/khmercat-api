<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\VideoReview;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Search restaurants and video posts by keyword. Guest-accessible.
     */
    public function index(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:1|max:100',
        ]);

        $q = $request->input('q');
        $tag = mb_strtolower(ltrim($q, '#'));

        $restaurants = Restaurant::where('name', 'like', "%{$q}%")
            ->orWhere('description', 'like', "%{$q}%")
            ->with('category')
            ->limit(20)
            ->get();

        $videos = VideoReview::where('status', 'ready')
            ->where(function ($query) use ($q, $tag) {
                $query->where('caption', 'like', "%{$q}%")
                    ->orWhereHas('hashtags', fn ($h) => $h->where('name', $tag));
            })
            ->with([
                'user:id,name,username,profile_picture',
                'restaurant:id,name,profile_picture',
                'hashtags:id,name',
            ])
            ->limit(20)
            ->get();

        return ApiResponse::success([
            'restaurants' => $restaurants,
            'videos' => $videos,
        ], 'Search results retrieved successfully.');
    }
}
