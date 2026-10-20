<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\ContentReport;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin tools: the dashboard counts, approving and deactivating restaurants,
 * reviewing reported videos, banning people, and the log of it all. Every
 * route is behind EnsureAdmin.
 */
class AdminController extends Controller
{
    private const PER_PAGE = 30;

    /** The dashboard's tiles (and the bottom bar's Reports badge). */
    public function summary()
    {
        return ApiResponse::success([
            'pending_restaurants' => Restaurant::where('status', Restaurant::STATUS_PENDING)->count(),
            'open_reports' => $this->openReportedVideos(),
            'users' => User::count(),
            'users_this_week' => User::where('created_at', '>=', now()->subWeek())->count(),
            'active_restaurants' => $this->activeRestaurants()->count(),
            'banned_users' => $this->bannedUsers()->count(),
        ]);
    }

    /**
     * Restaurants in a list: `pending` (default) waiting for approval,
     * `active` (approved and up), `deactivated` (taken down by an admin), or
     * `approved` / `rejected` by approval status. Newest first, with owner.
     */
    public function restaurants(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,active,deactivated',
            'page' => 'nullable|integer|min:1',
        ]);
        $status = $request->input('status', Restaurant::STATUS_PENDING);

        $query = match ($status) {
            'active' => $this->activeRestaurants(),
            'deactivated' => Restaurant::whereNotNull('deactivated_at'),
            default => Restaurant::where('status', $status),
        };

        $page = $query
            ->with([
                'category:id,name',
                'users' => fn ($q) => $q
                    ->wherePivot('role', 'owner')
                    ->select('users.id', 'users.name', 'users.username', 'users.profile_picture'),
            ])
            ->latest($status === 'deactivated' ? 'deactivated_at' : 'id')
            ->paginate(self::PER_PAGE);

        // Open reports on each one's videos, so trouble stands out.
        $ids = collect($page->items())->pluck('id');
        $openReports = ContentReport::query()
            ->where('content_reports.status', ContentReport::STATUS_OPEN)
            ->join('video_reviews', 'video_reviews.id', '=', 'content_reports.video_review_id')
            ->whereIn('video_reviews.restaurant_id', $ids)
            ->groupBy('video_reviews.restaurant_id')
            ->selectRaw('video_reviews.restaurant_id, count(*) as total')
            ->pluck('total', 'restaurant_id');

        return ApiResponse::success([
            'restaurants' => collect($page->items())->map(fn (Restaurant $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'profile_picture' => $r->profile_picture,
                'cover_picture' => $r->cover_picture,
                'address' => $r->address,
                'category' => $r->category,
                'status' => $r->status,
                'is_published' => $r->is_published,
                'rejection_reason' => $r->rejection_reason,
                'deactivated_at' => $r->deactivated_at,
                'deactivation_reason' => $r->deactivation_reason,
                'open_reports_count' => (int) ($openReports[$r->id] ?? 0),
                'created_at' => $r->created_at,
                'owner' => $r->users->first()?->only(['id', 'name', 'username', 'profile_picture']),
            ]),
            'counts' => [
                'pending' => Restaurant::where('status', Restaurant::STATUS_PENDING)->count(),
                'active' => $this->activeRestaurants()->count(),
                'deactivated' => Restaurant::whereNotNull('deactivated_at')->count(),
            ],
            'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function approveRestaurant(int $id)
    {
        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $restaurant->forceFill([
            'status' => Restaurant::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
        AdminAction::record('approve', 'restaurant', $restaurant->id, $restaurant->name);

        return ApiResponse::success(['status' => $restaurant->status], 'Restaurant approved.');
    }

    public function rejectRestaurant(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $restaurant->forceFill([
            'status' => Restaurant::STATUS_REJECTED,
            'approved_by' => auth()->id(),
            'approved_at' => null,
            'rejection_reason' => $data['reason'] ?? null,
        ])->save();
        AdminAction::record('reject', 'restaurant', $restaurant->id, $restaurant->name, $data['reason'] ?? null);

        return ApiResponse::success(['status' => $restaurant->status], 'Restaurant rejected.');
    }

    /** Takes a restaurant down: hidden everywhere but its team's own view. */
    public function deactivateRestaurant(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $restaurant->forceFill([
            'deactivated_at' => now(),
            'deactivation_reason' => trim($data['reason']),
            'deactivated_by' => auth()->id(),
        ])->save();
        AdminAction::record('deactivate', 'restaurant', $restaurant->id, $restaurant->name, $data['reason']);

        return ApiResponse::success(null, 'Restaurant deactivated.');
    }

    public function activateRestaurant(int $id)
    {
        $restaurant = Restaurant::find($id);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404);
        }

        $restaurant->forceFill([
            'deactivated_at' => null,
            'deactivation_reason' => null,
            'deactivated_by' => null,
        ])->save();
        AdminAction::record('activate', 'restaurant', $restaurant->id, $restaurant->name);

        return ApiResponse::success(null, 'Restaurant activated.');
    }

    /**
     * Reported videos, one row per video. `open` (default): with open
     * reports, most reported first, with a count per reason. `resolved`
     * (or `removed` / `dismissed`): handled ones, latest first, with who
     * handled them and how.
     */
    public function reports(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:open,resolved,removed,dismissed',
            'page' => 'nullable|integer|min:1',
        ]);
        $status = $request->input('status', ContentReport::STATUS_OPEN);
        $statuses = $status === 'resolved'
            ? [ContentReport::STATUS_REMOVED, ContentReport::STATUS_DISMISSED]
            : [$status];
        $open = $status === ContentReport::STATUS_OPEN;

        $grouped = ContentReport::query()
            ->whereIn('status', $statuses)
            ->select(
                'video_review_id',
                DB::raw('count(*) as reports_count'),
                DB::raw('max(id) as last_report_id'),
                DB::raw('max(resolved_at) as last_resolved_at'),
            )
            ->groupBy('video_review_id');
        $page = ($open
            ? $grouped->orderByDesc('reports_count')->orderByDesc('last_report_id')
            : $grouped->orderByDesc('last_resolved_at'))
            ->paginate(self::PER_PAGE);

        $videoIds = collect($page->items())->pluck('video_review_id');
        $videos = VideoReview::withTrashed()
            ->whereIn('id', $videoIds)
            ->with([
                'user:id,name,username,profile_picture,banned_at,banned_until',
                'restaurant:id,name,profile_picture',
            ])
            ->get()
            ->keyBy('id');
        $reports = ContentReport::query()
            ->whereIn('status', $statuses)
            ->whereIn('video_review_id', $videoIds)
            ->with(['reporter:id,name,username,profile_picture', 'resolver:id,name,username'])
            ->latest('id')
            ->get()
            ->groupBy('video_review_id');

        return ApiResponse::success([
            'items' => collect($page->items())->map(function ($row) use ($videos, $reports) {
                $list = $reports->get($row->video_review_id) ?? collect();
                $last = $list->first();
                $video = $videos->get($row->video_review_id);

                return [
                    'video' => $video,
                    'author_banned' => (bool) $video?->user?->isBanned(),
                    'reports_count' => (int) $row->reports_count,
                    'reason_counts' => $list->countBy('reason'),
                    'reports' => $list->take(5)
                        ->map(fn (ContentReport $r) => $r->only(['id', 'reason', 'note', 'created_at', 'reporter']))
                        ->values(),
                    'resolution' => $last && $last->status !== ContentReport::STATUS_OPEN ? [
                        'status' => $last->status,
                        'resolved_at' => $last->resolved_at,
                        'resolved_by' => $last->resolver?->only(['id', 'name', 'username']),
                    ] : null,
                ];
            })->filter(fn ($item) => $item['video'] !== null)->values(),
            'counts' => ['open' => $this->openReportedVideos()],
            'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    /**
     * Takes the video down (to the trash, like an owner's delete) and closes
     * every open report about it.
     */
    public function removeReportedVideo(int $videoId)
    {
        $video = VideoReview::with('user:id,username')->find($videoId);
        if ($video) {
            $video->delete();
        }

        $this->resolveReports($videoId, ContentReport::STATUS_REMOVED);
        AdminAction::record('remove_video', 'video', $videoId, 'Video by @'.($video?->user?->username ?? 'unknown'));

        return ApiResponse::success(null, 'Video removed.');
    }

    /**
     * Leaves the video up and closes its open reports.
     */
    public function dismissReports(int $videoId)
    {
        $video = VideoReview::withTrashed()->with('user:id,username')->find($videoId);
        $this->resolveReports($videoId, ContentReport::STATUS_DISMISSED);
        AdminAction::record('dismiss_reports', 'video', $videoId, 'Video by @'.($video?->user?->username ?? 'unknown'));

        return ApiResponse::success(null, 'Reports dismissed.');
    }

    /**
     * People: `all` (default, newest first), `reported` (with open reports
     * on their videos) or `banned`. `q` searches name, username, email and
     * phone.
     */
    public function users(Request $request)
    {
        $request->validate([
            'filter' => 'nullable|in:all,reported,banned',
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $filter = $request->input('filter', 'all');

        $query = match ($filter) {
            'reported' => $this->reportedUsers(),
            'banned' => $this->bannedUsers(),
            default => User::query(),
        };

        if ($request->filled('q')) {
            $q = '%'.ltrim(trim($request->input('q')), '@').'%';
            $query->where(fn (Builder $w) => $w
                ->where('name', 'like', $q)
                ->orWhere('username', 'like', $q)
                ->orWhere('email', 'like', $q)
                ->orWhere('phone_number', 'like', $q));
        }

        $page = $query
            ->select(['id', 'name', 'username', 'email', 'profile_picture', 'is_admin', 'banned_at', 'banned_until', 'ban_reason', 'created_at'])
            ->withCount(['videoReviews as videos_count' => fn ($v) => $v->videosOnly()])
            ->when($filter === 'banned', fn ($q) => $q->orderByDesc('banned_at'))
            ->latest('id')
            ->paginate(self::PER_PAGE);

        // Open reports on each one's videos.
        $ids = collect($page->items())->pluck('id');
        $openReports = ContentReport::query()
            ->where('content_reports.status', ContentReport::STATUS_OPEN)
            ->join('video_reviews', 'video_reviews.id', '=', 'content_reports.video_review_id')
            ->whereIn('video_reviews.user_id', $ids)
            ->groupBy('video_reviews.user_id')
            ->selectRaw('video_reviews.user_id, count(*) as total')
            ->pluck('total', 'user_id');

        return ApiResponse::success([
            'users' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'profile_picture' => $u->profile_picture,
                'is_admin' => (bool) $u->is_admin,
                'videos_count' => (int) $u->videos_count,
                'open_reports_count' => (int) ($openReports[$u->id] ?? 0),
                'is_banned' => $u->isBanned(),
                'banned_at' => $u->banned_at,
                'banned_until' => $u->banned_until,
                'ban_reason' => $u->ban_reason,
            ]),
            'counts' => [
                'reported' => $this->reportedUsers()->count(),
                'banned' => $this->bannedUsers()->count(),
            ],
            'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    /**
     * Bans someone for `days` (7 or 30) or, with none, for good. They're
     * signed out everywhere and told why when they try to log in.
     * `delete_reported_videos` also takes down their videos with open reports.
     */
    public function banUser(Request $request, int $id)
    {
        $data = $request->validate([
            'days' => 'nullable|integer|in:7,30',
            'reason' => ['required', Rule::in(array_keys(User::BAN_REASONS))],
            'delete_reported_videos' => 'nullable|boolean',
        ]);
        $user = User::find($id);
        if (! $user) {
            return ApiResponse::error('User not found.', 404);
        }
        if ($user->is_admin || $user->id === auth()->id()) {
            return ApiResponse::error('Admins can\'t be banned.', 422);
        }

        $deleted = 0;
        DB::transaction(function () use ($user, $data, &$deleted) {
            $user->forceFill([
                'banned_at' => now(),
                'banned_until' => isset($data['days']) ? now()->addDays((int) $data['days']) : null,
                'ban_reason' => $data['reason'],
                'banned_by' => auth()->id(),
            ])->save();

            if ($data['delete_reported_videos'] ?? false) {
                $videoIds = ContentReport::where('status', ContentReport::STATUS_OPEN)
                    ->whereIn('video_review_id', VideoReview::where('user_id', $user->id)->select('id'))
                    ->distinct()
                    ->pluck('video_review_id');
                VideoReview::whereIn('id', $videoIds)->get()->each->delete();
                foreach ($videoIds as $videoId) {
                    $this->resolveReports($videoId, ContentReport::STATUS_REMOVED);
                }
                $deleted = $videoIds->count();
            }
        });

        $length = isset($data['days']) ? "{$data['days']} days" : 'forever';
        AdminAction::record('ban', 'user', $user->id, "@{$user->username} · {$length}", $data['reason']);

        return ApiResponse::success([
            'banned_until' => $user->banned_until,
            'deleted_videos' => $deleted,
        ], 'User banned.');
    }

    public function unbanUser(int $id)
    {
        $user = User::find($id);
        if (! $user) {
            return ApiResponse::error('User not found.', 404);
        }

        $user->forceFill([
            'banned_at' => null,
            'banned_until' => null,
            'ban_reason' => null,
            'banned_by' => null,
        ])->save();
        AdminAction::record('unban', 'user', $user->id, "@{$user->username}");

        return ApiResponse::success(null, 'User unbanned.');
    }

    /** Every approve, ban and delete, latest first. */
    public function activity(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = AdminAction::with('admin:id,name,username,profile_picture')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return ApiResponse::success([
            'actions' => $page->items(),
            'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    private function resolveReports(int $videoId, string $status): void
    {
        ContentReport::where('video_review_id', $videoId)
            ->where('status', ContentReport::STATUS_OPEN)
            ->update([
                'status' => $status,
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
            ]);
    }

    private function openReportedVideos(): int
    {
        return ContentReport::where('status', ContentReport::STATUS_OPEN)
            ->distinct('video_review_id')
            ->count('video_review_id');
    }

    private function activeRestaurants(): Builder
    {
        return Restaurant::where('status', Restaurant::STATUS_APPROVED)->whereNull('deactivated_at');
    }

    private function bannedUsers(): Builder
    {
        return User::whereNotNull('banned_at')
            ->where(fn ($q) => $q->whereNull('banned_until')->orWhere('banned_until', '>', now()));
    }

    /** People with open reports on their videos. */
    private function reportedUsers(): Builder
    {
        return User::whereIn('id', VideoReview::withTrashed()
            ->whereIn('id', ContentReport::where('status', ContentReport::STATUS_OPEN)->select('video_review_id'))
            ->select('user_id'));
    }
}
