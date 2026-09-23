<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSave extends Model
{
    protected $fillable = [
        'video_review_id',
        'user_id',
    ];

    public function videoReview(): BelongsTo
    {
        return $this->belongsTo(VideoReview::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
