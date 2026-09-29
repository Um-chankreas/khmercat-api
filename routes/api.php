<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlacesController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\RestaurantMenuController;
use App\Http\Controllers\Api\RestaurantVideoController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VideoReviewController;
use App\Http\Middleware\EnsureAccountActive;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

// Authentication Endpoints
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:6,1');
    // Stricter: resend-otp triggers a real email/SMS send, so abuse here costs money.
    Route::post('resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:3,1');
});

/*
|--------------------------------------------------------------------------
| Public Routes (Guests + Authenticated users — view-only, no writes)
|--------------------------------------------------------------------------
*/

Route::get('videos/stream/{filename}', [VideoReviewController::class, 'streamVideo'])->name('videos.stream');
Route::get('videos/feed', [VideoReviewController::class, 'feed']);
Route::get('videos/{video}/comments', [CommentController::class, 'index'])->whereNumber('video');
Route::get('comments/{comment}/replies', [CommentController::class, 'replies'])->whereNumber('comment');
Route::get('videos/{video}', [VideoReviewController::class, 'show'])->whereNumber('video');
Route::post('videos/{video}/view', [VideoReviewController::class, 'recordView'])->whereNumber('video')->middleware('throttle:60,1');
Route::get('search', [SearchController::class, 'index']);
Route::get('users/{username}', [UserController::class, 'show']);
Route::get('users/{username}/videos', [UserController::class, 'videos']);
// Numeric constraint keeps this from swallowing the literal `restaurants/mine` route below.
Route::get('restaurants/{id}', [RestaurantController::class, 'show'])->whereNumber('id');
Route::get('restaurants/{id}/menu', [RestaurantMenuController::class, 'index'])->whereNumber('id');

/*
|--------------------------------------------------------------------------
| Protected Routes (Requires JWT Token)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', EnsureAccountActive::class])->group(function () {

    // Private-channel auth for Reverb (websockets), e.g. the per-user
    // notifications channel (`App.Models.User.{id}`, see channels.php).
    // The framework auto-registers `/broadcasting/auth` too, but only under
    // `web` (session) middleware — useless for this JWT-only API, so this
    // reuses Laravel's own controller under `auth:api` instead.
    Route::post('broadcasting/auth', [BroadcastController::class, 'authenticate']);

    // Authenticated User Profile & Session
    Route::prefix('auth')->group(function () {
        Route::get('get-user-account', [AuthController::class, 'getUserAccount']);
        Route::post('refresh-token', [AuthController::class, 'refreshToken']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Own account: deactivate (reversible) or delete (permanent). Throttled —
    // both check the password.
    Route::prefix('account')->middleware('throttle:10,1')->group(function () {
        Route::post('deactivate', [AccountController::class, 'deactivate']);
        Route::delete('/', [AccountController::class, 'destroy']);
    });

    // Restaurant Management & Team Invitations
    Route::prefix('restaurants')->group(function () {
        Route::post('create', [RestaurantController::class, 'create']);
        Route::get('mine', [RestaurantController::class, 'mine']);
        // Throttled: switching back to personal checks the password.
        Route::post('switch', [RestaurantController::class, 'switch'])->middleware('throttle:10,1');
        Route::post('videos/upload', [VideoReviewController::class, 'uploadRestaurantVideo']);
    });

    // Video Reviews (normal users reviewing a restaurant they select)
    Route::prefix('reviews')->group(function () {
        Route::post('upload', [VideoReviewController::class, 'uploadReview']);
    });

    // Likes, Saves & Comments
    Route::prefix('videos/{video}')->whereNumber('video')->group(function () {
        Route::post('like', [VideoReviewController::class, 'like']);
        Route::delete('like', [VideoReviewController::class, 'unlike']);
        Route::post('save', [VideoReviewController::class, 'save']);
        Route::delete('save', [VideoReviewController::class, 'unsave']);
        Route::post('comments', [CommentController::class, 'store']);
    });
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);
    Route::post('comments/{comment}/like', [CommentController::class, 'toggleLike']);

    // Following (for the Following feed tab)
    Route::post('users/{username}/follow', [UserController::class, 'follow']);
    Route::delete('users/{username}/follow', [UserController::class, 'unfollow']);

    // Private profile tabs (only the profile owner can view their own list)
    Route::get('users/{username}/likes', [UserController::class, 'likedVideos']);
    Route::get('users/{username}/saves', [UserController::class, 'savedVideos']);
    Route::prefix('restaurants/{id}')->whereNumber('id')->group(function () {
        // Owner/manager tools: edit details, logo and cover.
        Route::put('/', [RestaurantController::class, 'update']);
        Route::delete('/', [RestaurantController::class, 'destroy'])->middleware('throttle:10,1');
        Route::post('avatar', [RestaurantController::class, 'uploadAvatar']);
        Route::post('cover', [RestaurantController::class, 'uploadCover']);
        Route::post('menu', [RestaurantMenuController::class, 'store']);
        Route::delete('menu/{imageId}', [RestaurantMenuController::class, 'destroy'])->whereNumber('imageId');
        // The restaurant's own posts: delete one, and the Delete tab's list.
        Route::get('videos/deleted', [RestaurantVideoController::class, 'deleted']);
        Route::delete('videos/{videoId}', [RestaurantVideoController::class, 'destroy'])->whereNumber('videoId');
        Route::post('follow', [RestaurantController::class, 'follow']);
        Route::delete('follow', [RestaurantController::class, 'unfollow']);
    });

    Route::get('/places/autocomplete', [PlacesController::class, 'autocomplete']);
    Route::get('/places/details', [PlacesController::class, 'details']);

    // Push notification device tokens (Firebase Cloud Messaging).
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

    // In-app notifications list (follows, likes, comments, replies, restaurant posts).
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('read-all', [NotificationController::class, 'markAllRead']);
        Route::post('read', [NotificationController::class, 'markManyRead']);
        Route::delete('batch', [NotificationController::class, 'destroyMany']);
        Route::post('{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('/', [NotificationController::class, 'destroyAll']);
        Route::delete('{id}', [NotificationController::class, 'destroy']);
    });

    // Own avatar / cover photo upload, and the edit-profile form.
    Route::prefix('profile')->group(function () {
        Route::post('avatar', [ProfileController::class, 'uploadAvatar']);
        Route::post('cover', [ProfileController::class, 'uploadCover']);
        Route::get('edit', [ProfileController::class, 'editProfile']);
        Route::put('edit', [ProfileController::class, 'updateProfile']);
    });

    // My-profile management (Videos / Favorite / Delete tabs) — owner-only.
    Route::prefix('profile/{userId}')->whereNumber('userId')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::get('posts', [ProfileController::class, 'posts']);
        Route::post('posts/{postId}/favorite', [ProfileController::class, 'toggleFavorite'])->whereNumber('postId');
        Route::delete('posts/{postId}', [ProfileController::class, 'destroyPost'])->whereNumber('postId');
    });

});
