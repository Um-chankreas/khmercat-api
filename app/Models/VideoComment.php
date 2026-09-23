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
        'parent_id',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(VideoComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(VideoComment::class, 'parent_id');
    }
}
