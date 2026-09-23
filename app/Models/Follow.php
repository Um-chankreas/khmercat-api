<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Follow extends Model
{
    protected $fillable = [
        'follower_id',
        'followable_id',
        'followable_type',
    ];

    /**
     * The user doing the following.
     */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /**
     * The user or restaurant being followed.
     */
    public function followable(): MorphTo
    {
        return $this->morphTo();
    }
}
