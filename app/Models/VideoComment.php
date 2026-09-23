<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoComment extends Model
{
    protected $fillable = [
        'video_review_id',
        'user_id',
        'body',
    ];

    public function videoReview(): BelongsTo
    {
        return $this->belongsTo(VideoReview::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(CommentLike::class);
    }
}
