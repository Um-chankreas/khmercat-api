<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\Restaurant;
use App\Models\VideoReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin tools: approving restaurants before they go public, and reviewing
 * reported videos. Every route is behind EnsureAdmin.
 */
class AdminController extends Controller
{
    private const PER_PAGE = 30;

    /**
     * Restaurants by approval status (`pending` by default), newest first,
     * with their owner.
     */
    public function restaurants(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:'.implode(',', Restaurant::STATUSES),
            'page' => 'nullable|integer|min:1',
        ]);
        $status = $request->input('status', Restaurant::STATUS_PENDING);

        $page = Restaurant::query()
            ->where('status', $status)
            ->with([
                'category:id,name',
                'users' => fn ($q) => $q
                    ->wherePivot('role', 'owner')
                    ->select('users.id', 'users.name', 'users.username', 'users.profile_picture'),
            ])
            ->latest('id')
            ->paginate(self::PER_PAGE);

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
                'created_at' => $r->created_at,
                'owner' => $r->users->first()?->only(['id', 'name', 'username', 'profile_picture']),
            ]),
            'counts' => [
                'pending' => Restaurant::where('status', Restaurant::STATUS_PENDING)->count(),
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

        return ApiResponse::success(['status' => $restaurant->status], 'Restaurant rejected.');
    }

    /**
     * Reported videos (`open` by default), one row per video with how many
     * open reports it has and the reasons given, most reported first.
     */
    public function reports(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:open,removed,dismissed',
            'page' => 'nullable|integer|min:1',
        ]);
        $status = $request->input('status', ContentReport::STATUS_OPEN);

        $page = ContentReport::query()
            ->where('status', $status)
            ->select('video_review_id', DB::raw('count(*) as reports_count'), DB::raw('max(id) as last_report_id'))
            ->groupBy('video_review_id')
            ->orderByDesc('reports_count')
            ->orderByDesc('last_report_id')
            ->paginate(self::PER_PAGE);

        $videoIds = collect($page->items())->pluck('video_review_id');
        $videos = VideoReview::withTrashed()
            ->whereIn('id', $videoIds)
            ->with(['user:id,name,username,profile_picture', 'restaurant:id,name,profile_picture'])
            ->get()
            ->keyBy('id');
        $reports = ContentReport::query()
            ->where('status', $status)
            ->whereIn('video_review_id', $videoIds)
            ->with('reporter:id,name,username,profile_picture')
            ->latest('id')
            ->get()
            ->groupBy('video_review_id');

        return ApiResponse::success([
            'items' => collect($page->items())->map(fn ($row) => [
                'video' => $videos->get($row->video_review_id),
                'reports_count' => (int) $row->reports_count,
                'reports' => ($reports->get($row->video_review_id) ?? collect())
                    ->take(5)
                    ->map(fn (ContentReport $r) => $r->only(['id', 'reason', 'note', 'created_at', 'reporter']))
                    ->values(),
            ])->filter(fn ($item) => $item['video'] !== null)->values(),
            'counts' => [
                'open' => ContentReport::where('status', ContentReport::STATUS_OPEN)
                    ->distinct('video_review_id')
                    ->count('video_review_id'),
            ],
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
        $video = VideoReview::find($videoId);
        if ($video) {
            $video->delete();
        }

        $this->resolveReports($videoId, ContentReport::STATUS_REMOVED);

        return ApiResponse::success(null, 'Video removed.');
    }

    /**
     * Leaves the video up and closes its open reports.
     */
    public function dismissReports(int $videoId)
    {
        $this->resolveReports($videoId, ContentReport::STATUS_DISMISSED);

        return ApiResponse::success(null, 'Reports dismissed.');
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
}
