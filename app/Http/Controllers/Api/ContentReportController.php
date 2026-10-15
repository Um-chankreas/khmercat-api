<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\VideoReview;
use Illuminate\Http\Request;

/**
 * Users reporting a video (long-press → Report in the app). Admins review
 * them under Admin → Reported content.
 */
class ContentReportController extends Controller
{
    public function store(Request $request, VideoReview $video)
    {
        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', ContentReport::REASONS),
            'note' => 'nullable|string|max:500',
        ]);

        if ($video->user_id === auth()->id()) {
            return ApiResponse::error('You can\'t report your own video.', 422);
        }

        // Reporting again while the first is still open just updates it.
        ContentReport::updateOrCreate(
            [
                'reporter_id' => auth()->id(),
                'video_review_id' => $video->id,
                'status' => ContentReport::STATUS_OPEN,
            ],
            [
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
            ]
        );

        return ApiResponse::success(null, 'Thanks for reporting. Our team will review it.');
    }
}
