<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Registers (or re-registers) this device's FCM token against the
     * signed-in user. `updateOrCreate` by token so re-installing the app,
     * switching accounts on the same device, or a token that Firebase
     * rotates all just move the same row rather than creating duplicates.
     */
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'nullable|in:android,ios,web',
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $request->token],
            ['user_id' => auth()->id(), 'platform' => $request->platform]
        );

        return ApiResponse::success(null, 'Device registered.');
    }

    /**
     * Unregisters this device's token — call on logout so a shared/reset
     * device stops receiving push notifications for the account that just
     * signed out. Only removes it if it belongs to the signed-in user.
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:512',
        ]);

        DeviceToken::where('token', $request->token)
            ->where('user_id', auth()->id())
            ->delete();

        return ApiResponse::success(null, 'Device unregistered.');
    }
}
