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
}
