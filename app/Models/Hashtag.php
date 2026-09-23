<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Hashtag extends Model
{
    protected $fillable = [
        'name',
    ];

    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(VideoReview::class, 'hashtag_video')->withTimestamps();
    }
}
