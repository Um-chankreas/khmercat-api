<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Restaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'address',
        'phone',
        'facebook_url',
        'tiktok_url',
        'telegram_username',
        'price_level',
        'service_type',
        'delivery_time_min',
        'delivery_time_max',
        'opening_time',
        'closing_time',
        'profile_picture',
        'profile_thumbnail',
        'cover_picture',
        'cover_thumbnail',
        'latitude',
        'longitude',
        'status',
        'approved_by',
        'is_sponsored',
        'is_published',
    ];

    /**
     * Opening hours are stored as local wall-clock times in this timezone.
     */
    public const BUSINESS_TIMEZONE = 'Asia/Phnom_Penh';

    public const SERVICE_TYPES = ['dine_in', 'delivery', 'dine_in_delivery', 'takeaway'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'price_level' => 'integer',
            'delivery_time_min' => 'integer',
            'delivery_time_max' => 'integer',
            'is_sponsored' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    /**
     * Whether the restaurant is open right now, or null when its hours
     * aren't set. A closing time earlier than the opening time means it
     * closes after midnight (e.g. 17:00 – 02:00).
     */
    public function isOpenNow(?\DateTimeInterface $at = null): ?bool
    {
        if (! $this->opening_time || ! $this->closing_time) {
            return null;
        }

        $now = Carbon::instance($at ?? now())
            ->setTimezone(self::BUSINESS_TIMEZONE)
            ->format('H:i:s');
        $open = substr($this->opening_time, 0, 8);
        $close = substr($this->closing_time, 0, 8);

        if ($open === $close) {
            return true; // Same open and close time: open around the clock.
        }

        return $open < $close
            ? $now >= $open && $now < $close
            : $now >= $open || $now < $close;
    }

    /**
     * Great-circle distance in kilometres to the given point, or null when
     * this restaurant has no coordinates.
     */
    public function distanceKmTo(float $lat, float $lng): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $dLat = deg2rad($this->latitude - $lat);
        $dLng = deg2rad($this->longitude - $lng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat)) * cos(deg2rad($this->latitude)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
     * Only restaurants visible to the public (not unpublished by their owner).
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Whether [userId] is on this restaurant's team (any accepted role) —
     * the people who can still see it while it's unpublished.
     */
    public function hasMember(?int $userId): bool
    {
        return $userId !== null && $this->users()
            ->where('users.id', $userId)
            ->wherePivot('status', 'accepted')
            ->exists();
    }

    /**
     * Menu pages (photos), in display order.
     */
    public function menuImages(): HasMany
    {
        return $this->hasMany(RestaurantMenuImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Public web page with the menu — what the restaurant's QR code opens,
     * so diners can scan it without the app.
     */
    public function menuWebUrl(): string
    {
        return route('restaurants.menu.web', ['restaurant' => $this->id]);
    }

    /**
     * Users following this restaurant.
     */
    public function followers(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    /**
     * The actual User models following this restaurant (unwraps the Follow
     * pivot rows `followers()` returns) — for fanning out notifications.
     */
    public function followerUsers(): Collection
    {
        return $this->followers()->with('follower')->get()->pluck('follower')->filter()->values();
    }
}
