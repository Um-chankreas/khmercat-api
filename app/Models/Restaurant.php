<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'address',
        'profile_picture',
        'profile_thumbnail',
        'cover_picture',
        'cover_thumbnail',
        'latitude',
        'longitude',
        'status',
        'approved_by',

    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    // --- Relationships ---

    /**
     * Category this restaurant belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RestaurantCategory::class, 'category_id');
    }

    /**
     * Users associated with this restaurant (Owners, Managers, Staff).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'restaurant_user')
            ->using(RestaurantUser::class)
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    /**
     * Pending team invitations for this restaurant.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(RestaurantInvitation::class);
    }

    /**
     * Customer video reviews left for this restaurant.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(VideoReview::class)->where('type', VideoReview::TYPE_REVIEW);
    }

    /**
     * Videos the restaurant itself has posted.
     */
    public function videos(): HasMany
    {
        return $this->hasMany(VideoReview::class)->where('type', VideoReview::TYPE_RESTAURANT_POST);
    }

    /**
     * Users following this restaurant.
     */
    public function followers(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }
}
