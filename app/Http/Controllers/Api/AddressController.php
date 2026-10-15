<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use Illuminate\Http\Request;

/**
 * The signed-in user's saved addresses (Menu → Address). Every action is
 * scoped to `auth()->user()`, so an id belonging to someone else is simply
 * not found.
 */
class AddressController extends Controller
{
    // Enough for home, work and a few others; stops the list being used as
    // free storage.
    private const MAX_ADDRESSES = 20;

    public function index()
    {
        return ApiResponse::success(
            auth()->user()->addresses()->latest('id')->get(),
            'Addresses retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if (auth()->user()->addresses()->count() >= self::MAX_ADDRESSES) {
            return ApiResponse::error('You can save up to '.self::MAX_ADDRESSES.' addresses.', 422);
        }

        return ApiResponse::success(
            auth()->user()->addresses()->create($data),
            'Address saved.'
        );
    }

    public function update(Request $request, int $id)
    {
        $address = auth()->user()->addresses()->find($id);
        if (! $address) {
            return ApiResponse::error('Address not found.', 404);
        }

        $address->update($this->validated($request));

        return ApiResponse::success($address, 'Address updated.');
    }

    public function destroy(int $id)
    {
        $address = auth()->user()->addresses()->find($id);
        if (! $address) {
            return ApiResponse::error('Address not found.', 404);
        }

        $address->delete();

        return ApiResponse::success(null, 'Address deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => 'required|in:'.implode(',', UserAddress::LABELS),
            'address' => 'required|string|max:500',
            'detail' => 'nullable|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);
    }
}
