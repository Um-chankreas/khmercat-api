<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\PlacesController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VideoReviewController;
use Illuminate\Support\Facades\Route;

// Authentication Endpoints
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
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
Route::get('search', [SearchController::class, 'index']);
Route::get('users/{username}', [UserController::class, 'show']);
Route::get('users/{username}/videos', [UserController::class, 'videos']);
// Numeric constraint keeps this from swallowing the literal `restaurants/mine` route below.
Route::get('restaurants/{id}', [RestaurantController::class, 'show'])->whereNumber('id');

/*
|--------------------------------------------------------------------------
| Protected Routes (Requires JWT Token)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:api')->group(function () {

    // Authenticated User Profile & Session
    Route::prefix('auth')->group(function () {
        Route::get('get-user-account', [AuthController::class, 'getUserAccount']);
        Route::post('refresh-token', [AuthController::class, 'refreshToken']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Restaurant Management & Team Invitations
    Route::prefix('restaurants')->group(function () {
        Route::post('create', [RestaurantController::class, 'create']);
        Route::get('mine', [RestaurantController::class, 'mine']);
        Route::post('switch', [RestaurantController::class, 'switch']);
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
        Route::post('follow', [RestaurantController::class, 'follow']);
        Route::delete('follow', [RestaurantController::class, 'unfollow']);
    });

    Route::get('/places/autocomplete', [PlacesController::class, 'autocomplete']);
    Route::get('/places/details', [PlacesController::class, 'details']);

    // Push notification device tokens (Firebase Cloud Messaging).
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

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
