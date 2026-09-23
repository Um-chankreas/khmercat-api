<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePlacesService
{
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.google.places_key');
    }

    /**
     * Search for a business by name + address using Text Search (New).
     * Returns the best match, or null if nothing found.
     */
    public function findBusiness(string $name, string $address): ?array
    {
        try {
            $response = Http::timeout(5)->withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.businessStatus,places.location,places.rating',
            ])->post('https://places.googleapis.com/v1/places:searchText', [
                'textQuery' => "{$name}, {$address}",
            ]);
        } catch (ConnectionException $e) {
            Log::error('Google Places findBusiness connection failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Google Places findBusiness failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        $places = $response->json('places', []);

        return $places[0] ?? null;
    }

    /**
     * Decide if a match is "verified" — operational + reasonably matches name.
     */
    public function isVerifiedMatch(array $place, string $submittedName): bool
    {
        $isOperational = ($place['businessStatus'] ?? null) === 'OPERATIONAL';

        similar_text(
            strtolower($submittedName),
            strtolower($place['displayName']['text'] ?? ''),
            $similarity
        );

        return $isOperational && $similarity >= 60;
    }

    /**
     * Autocomplete suggestions as the user types a place name/address.
     */
    public function autocomplete(string $input, ?string $sessionToken = null): array
    {
        $body = [
            'input' => $input,
            'includedRegionCodes' => ['kh'],
            'locationBias' => [
                'circle' => [
                    'center' => [
                        'latitude' => 11.5564,
                        'longitude' => 104.9282,
                    ],
                    'radius' => 50000.0,
                ],
            ],
        ];

        if ($sessionToken) {
            $body['sessionToken'] = $sessionToken;
        }

        try {
            $response = Http::timeout(5)->withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey,
            ])->post('https://places.googleapis.com/v1/places:autocomplete', $body);
        } catch (ConnectionException $e) {
            Log::error('Google Places autocomplete connection failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Google Places autocomplete failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'input' => $input,
            ]);

            return [];
        }

        $suggestions = $response->json('suggestions', []);

        return collect($suggestions)
            ->map(function ($s) {
                $prediction = $s['placePrediction'] ?? null;
                if (! $prediction) {
                    return null;
                }

                return [
                    'place_id' => $prediction['placeId'] ?? null,
                    'description' => $prediction['text']['text'] ?? '',
                ];
            })
            ->filter(fn ($item) => $item !== null && $item['place_id'] !== null)
            ->values()
            ->all();
    }

    /**
     * Fetch full details for a specific place ID.
     */
    public function getPlaceDetails(string $placeId, ?string $sessionToken = null): ?array
    {
        $headers = [
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'id,displayName,formattedAddress,location,businessStatus',
        ];

        $query = [];
        if ($sessionToken) {
            $query['sessionToken'] = $sessionToken;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders($headers)
                ->get("https://places.googleapis.com/v1/places/{$placeId}", $query);
        } catch (ConnectionException $e) {
            Log::error('Google Places getPlaceDetails connection failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Google Places getPlaceDetails failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'place_id' => $placeId,
            ]);

            return null;
        }

        $data = $response->json();

        return [
            'place_id' => $data['id'] ?? $placeId,
            'name' => $data['displayName']['text'] ?? null,
            'address' => $data['formattedAddress'] ?? null,
            'lat' => $data['location']['latitude'] ?? null,
            'lng' => $data['location']['longitude'] ?? null,
            'business_status' => $data['businessStatus'] ?? null,
        ];
    }
}
