<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CommentLike;
use App\Models\User;
use App\Models\VideoComment;
use App\Models\VideoLike;
use App\Models\VideoSave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The signed-in user's own account: deactivate (reversible) or delete
 * (permanent). Both are confirmed with the account password.
 */
class AccountController extends Controller
{
    /**
     * POST /account/deactivate — hides the profile, videos and comments and
     * signs out everywhere. Logging in again reactivates the account.
     */
    public function deactivate(Request $request)
    {
        $user = auth()->user();
        if ($error = $this->checkPassword($request, $user)) {
            return $error;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'deactivated_at' => now(),
                'active_restaurant_id' => null,
            ])->save();
            // No pushes to a deactivated account.
            $user->deviceTokens()->delete();
        });

        $this->logoutQuietly();

        return ApiResponse::success(null, 'Account deactivated.');
    }

    /**
     * DELETE /account — permanently removes the account and everything the
     * user made. Restaurants they own are deleted with it; teams they only
     * belong to just lose them.
     */
    public function destroy(Request $request)
    {
        $user = auth()->user();
        if ($error = $this->checkPassword($request, $user)) {
            return $error;
        }

        DB::transaction(function () use ($user) {
            foreach ($user->ownedRestaurants()->get() as $restaurant) {
                User::where('active_restaurant_id', $restaurant->id)->update(['active_restaurant_id' => null]);
                $restaurant->delete();
            }
            $user->restaurants()->detach();

            $user->videoReviews()->delete();
            // Replies and likes on these comments cascade with them.
            VideoComment::where('user_id', $user->id)->delete();
            VideoLike::where('user_id', $user->id)->delete();
            VideoSave::where('user_id', $user->id)->delete();
            CommentLike::where('user_id', $user->id)->delete();
            $user->following()->delete();
            $user->followers()->delete();
            $user->deviceTokens()->delete();

            // The row is soft-deleted, so free the unique email and username
            // for a future sign-up and drop the personal details.
            $user->forceFill([
                'email' => "deleted_{$user->id}@deleted.invalid",
                'username' => "deleted_{$user->id}",
                'phone_number' => null,
                'active_restaurant_id' => null,
            ])->save();
            $user->delete();
        });

        $this->logoutQuietly();

        return ApiResponse::success(null, 'Account deleted.');
    }

    private function checkPassword(Request $request, User $user)
    {
        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->input('password'), $user->password)) {
            return ApiResponse::validationError(
                ['password' => ['Incorrect password.']],
                'Incorrect password.'
            );
        }

        return null;
    }

    /**
     * Blacklists the current token. The account change already happened, so
     * a failure here shouldn't turn the response into an error.
     */
    private function logoutQuietly(): void
    {
        try {
            auth('api')->logout();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
