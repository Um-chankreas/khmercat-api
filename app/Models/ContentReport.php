<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentReport extends Model
{
    /**
     * What the app offers. `inappropriate` and `harassment` are from the
     * first version of the list and stay valid for older reports.
     */
    public const REASONS = [
        'sexual', 'hate_harassment', 'violence', 'child_safety', 'misleading',
        'ai_content', 'self_harm', 'branded', 'spam', 'copyright',
        'inappropriate', 'harassment', 'other',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_REMOVED = 'removed';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'reporter_id',
        'video_review_id',
        'reason',
        'note',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** The admin who removed the video or dismissed the report. */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * Includes trashed videos, so a report still shows what it was about
     * after the video is removed.
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoReview::class, 'video_review_id')->withTrashed();
    }
}
