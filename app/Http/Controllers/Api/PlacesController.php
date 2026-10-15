<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\GooglePlacesService;
use Illuminate\Http\Request;

class PlacesController extends Controller
{
    public function autocomplete(Request $request, GooglePlacesService $places)
    {
        $input = $request->query('input');
        if (! $input) {
            return ApiResponse::error('Missing input parameter', 422);
        }

        return ApiResponse::success($places->autocomplete($input));
    }

    public function details(Request $request, GooglePlacesService $places)
    {
        $placeId = $request->query('place_id');
        if (! $placeId) {
            return ApiResponse::error('Missing place_id parameter', 422);
        }

        $details = $places->getPlaceDetails($placeId);
        if (! $details) {
            return ApiResponse::error('Place not found', 404);
        }

        return ApiResponse::success($details);
    }

    /**
     * The street address at a map pin (reverse geocoding), for the saved
     * address screen. 404 when Google has nothing for that spot.
     */
    public function reverse(Request $request, GooglePlacesService $places)
    {
        $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $address = $places->reverseGeocode($request->float('lat'), $request->float('lng'));
        if (! $address) {
            return ApiResponse::error('No address found for this location.', 404);
        }

        return ApiResponse::success(['address' => $address]);
    }
}
