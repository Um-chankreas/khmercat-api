<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VideoLike;
use App\Models\VideoReview;
use App\Models\VideoSave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * "My profile" endpoints — the signed-in user managing their own posts
 * (Videos / Favorite / Delete tabs). Every route here requires the path's
 * `{userId}` to match the signed-in user; this surfaces the private email
 * field and lets the owner favorite/soft-delete their own content, so it's
 * not exposed for viewing anyone else's profile (see UserController for the
 * public-facing `{username}` profile routes).
 */
class ProfileController extends Controller
{
    public function show(int $userId)
    {
        if ($error = $this->ensureOwner($userId)) {
            return $error;
        }

        $user = auth()->user();
        $user->loadCount(['followers', 'following']);

        $postsCount = VideoReview::where('user_id', $user->id)->count();
        $likesCount = VideoLike::whereHas(
            'videoReview', fn ($q) => $q->where('user_id', $user->id)
        )->count();

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar' => $user->profile_picture,
            'bio' => $user->bio,
            'email' => $user->email,
            'following_count' => $user->following_count,
            'followers_count' => $user->followers_count,
            'posts_count' => $postsCount,
            'likes_count' => $likesCount,
        ], 'Profile retrieved successfully.');
    }

    /**
     * Replaces the signed-in user's avatar. No `{userId}` in the path — the
     * upload is always for whoever the bearer token belongs to.
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048', // 2MB
        ]);

        $url = $this->replaceProfileImage($request->file('avatar'), 'avatar');
        auth()->user()->update(['profile_picture' => $url]);

        return ApiResponse::success(['avatar_url' => $url], 'Avatar updated successfully.');
    }

    /**
     * Replaces the signed-in user's cover photo.
     */
    public function uploadCover(Request $request)
    {
        $request->validate([
            'cover' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120', // 5MB
        ]);

        $url = $this->replaceProfileImage($request->file('cover'), 'cover');
        auth()->user()->update(['cover_picture' => $url]);

        return ApiResponse::success(['cover_url' => $url], 'Cover updated successfully.');
    }

    /**
     * Current user's data to pre-fill the Edit Profile form.
     */
    public function editProfile()
    {
        return ApiResponse::success(
            ['user' => $this->formatUser(auth()->user())],
            'Profile data retrieved successfully.'
        );
    }

    /**
     * Full replace of the editable profile fields, including social links —
     * matches PUT semantics and the edit form always submitting the whole
     * thing, so an omitted `social_links` entry clears that link rather than
     * leaving the old value in place.
     */
    public function updateProfile(Request $request)
    {
        $userId = auth()->id();

        $request->validate([
            'name' => 'required|string|max:100',
            'bio' => 'nullable|string|max:300',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => [
                'nullable',
                'string',
                'regex:/^\+?[0-9\s\-]{6,20}$/',
                Rule::unique('users', 'phone_number')->ignore($userId),
            ],
            'social_links' => 'nullable|array',
            'social_links.facebook' => 'nullable|url',
            'social_links.tiktok' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (! preg_match('/^(https?:\/\/\S+|@?[\w.]{2,24})$/', $value)) {
                    $fail('The tiktok link must be a valid URL or username.');
                }
            }],
            'social_links.telegram' => 'nullable|string|regex:/^@/',
        ]);

        $links = $request->input('social_links', []);

        $user = auth()->user();
        $user->update([
            'name' => $request->input('name'),
            'bio' => $request->input('bio'),
            'email' => $request->input('email'),
            'phone_number' => $request->input('phone'),
            'facebook_url' => $links['facebook'] ?? null,
            'tiktok_url' => $links['tiktok'] ?? null,
            'telegram_username' => $links['telegram'] ?? null,
        ]);

        return ApiResponse::success(
            ['user' => $this->formatUser($user->fresh())],
            'Profile updated successfully.'
        );
    }

    /**
     * `flag=favorite` -> posts this user has saved/bookmarked (any user's
     * video). `flag=deleted` -> this user's own posts sitting in the trash.
     * No flag -> this user's own posts, every status, none deleted.
     */
    public function posts(int $userId, Request $request)
    {
        $request->validate([
            'flag' => 'nullable|in:favorite,deleted',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($error = $this->ensureOwner($userId)) {
            return $error;
        }

        $flag = $request->input('flag');

        $query = match ($flag) {
            'favorite' => VideoReview::whereHas('saves', fn ($q) => $q->where('user_id', $userId)),
            'deleted' => VideoReview::onlyTrashed()->where('user_id', $userId),
            default => VideoReview::where('user_id', $userId),
        };

        $query->with([
            'user:id,name,username,profile_picture',
            'restaurant:id,name,profile_picture',
        ])
            ->withCount(['likes', 'comments'])
            ->withExists([
                'likes as is_liked' => fn ($q) => $q->where('user_id', $userId),
                'saves as is_favorite' => fn ($q) => $q->where('user_id', $userId),
            ])
            ->orderByDesc('id');

        $perPage = $request->integer('limit', 10);
        $page = $request->integer('page', 1);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return ApiResponse::success([
            'data' => $paginator->getCollection()
                ->map(fn (VideoReview $video) => $this->formatPost($video))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            ],
        ], 'Posts retrieved successfully.');
    }

    /**
     * Toggles favorite status for any post (not just the user's own) on
     * behalf of the profile owner, reusing the same `video_saves` table the
     * feed's save/unsave endpoints use.
     */
    public function toggleFavorite(int $userId, int $postId)
    {
        if ($error = $this->ensureOwner($userId)) {
            return $error;
        }

        $video = VideoReview::withTrashed()->find($postId);

        if (! $video) {
            return ApiResponse::error('Post not found.', 404);
        }

        $save = VideoSave::where('video_review_id', $video->id)->where('user_id', $userId)->first();

        if ($save) {
            $save->delete();
            $isFavorite = false;
        } else {
            VideoSave::create(['video_review_id' => $video->id, 'user_id' => $userId]);
            $isFavorite = true;
        }

        $video->loadMissing(['user:id,name,username,profile_picture', 'restaurant:id,name,profile_picture']);
        $video->loadCount(['likes', 'comments']);
        $video->setAttribute('is_favorite', $isFavorite);
        $video->setAttribute('is_liked', VideoLike::where('video_review_id', $video->id)->where('user_id', $userId)->exists());

        return ApiResponse::success(
            $this->formatPost($video),
            $isFavorite ? 'Post marked as favorite.' : 'Post removed from favorites.'
        );
    }

    /**
     * Soft-deletes one of this user's own posts (sets `deleted_at`). It's
     * kept in the trash — visible via `flag=deleted` — until
     * `videos:prune-trashed` permanently purges it after 30 days.
     */
    public function destroyPost(int $userId, int $postId)
    {
        if ($error = $this->ensureOwner($userId)) {
            return $error;
        }

        $video = VideoReview::where('user_id', $userId)->find($postId);

        if (! $video) {
            return ApiResponse::error('Post not found.', 404);
        }

        $video->delete();

        return ApiResponse::success(null, 'Post deleted successfully.');
    }

    private function formatPost(VideoReview $video): array
    {
        return [
            'id' => $video->id,
            'caption' => $video->caption,
            'rating' => $video->rating,
            'video_url' => $video->video_url,
            'thumbnail_url' => $video->thumbnail_url,
            'aspect_ratio' => $video->aspect_ratio,
            'user' => $video->user,
            'restaurant' => $video->restaurant,
            'likes_count' => $video->likes_count,
            'comments_count' => $video->comments_count,
            'is_favorite' => (bool) $video->is_favorite,
            'is_liked' => (bool) $video->is_liked,
            'status' => $video->status,
            'deleted_at' => $video->deleted_at,
        ];
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'bio' => $user->bio,
            'email' => $user->email,
            'phone' => $user->phone_number,
            'avatar_url' => $user->profile_picture,
            'social_links' => [
                'facebook' => $user->facebook_url,
                'tiktok' => $user->tiktok_url,
                'telegram' => $user->telegram_username,
            ],
            'updated_at' => $user->updated_at,
        ];
    }

    /**
     * Stores $file as `profiles/{userId}/{baseName}.{ext}` on the `public`
     * disk, first deleting any existing file with that base name — the
     * upload might switch format (e.g. a PNG replacing a previous JPG), so a
     * plain overwrite by path wouldn't clean up the old one.
     */
    private function replaceProfileImage(UploadedFile $file, string $baseName): string
    {
        $directory = 'profiles/'.auth()->id();

        foreach (Storage::disk('public')->files($directory) as $existing) {
            if (pathinfo($existing, PATHINFO_FILENAME) === $baseName) {
                Storage::disk('public')->delete($existing);
            }
        }

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        $filename = "{$baseName}.{$extension}";
        Storage::disk('public')->putFileAs($directory, $file, $filename);

        return Storage::disk('public')->url("{$directory}/{$filename}");
    }

    private function ensureOwner(int $userId): ?JsonResponse
    {
        if (auth()->id() !== $userId) {
            return ApiResponse::error('You can only access your own profile.', 403);
        }

        if (! User::whereKey($userId)->exists()) {
            return ApiResponse::error('User not found.', 404);
        }

        return null;
    }
}
