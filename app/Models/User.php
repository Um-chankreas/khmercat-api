<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable,SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone_number',
        'profile_picture',
        'profile_thumbnail',
        'cover_picture',
        'cover_thumbnail',
        'bio',
        'is_verified',
        'facebook_url',
        'tiktok_url',
        'telegram_username',
        'email_otp',
        'email_otp_expires_at',
        'email_verified_at',
        'terms_accepted_at',
        'active_restaurant_id',
        'deactivated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'banned_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'banned_at' => 'datetime',
            'banned_until' => 'datetime',
            'password' => 'hashed',
            'is_verified' => 'boolean',
            // Not fillable on purpose: only `php artisan admin:create` sets it.
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Every video this user uploaded (reviews and restaurant posts).
     */
    public function videoReviews(): HasMany
    {
        return $this->hasMany(VideoReview::class);
    }

    /**
     * Only users who haven't deactivated their account — the ones whose
     * profile, videos and comments the public can see.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }

    /** Banned right now (a timed ban that has run out no longer counts). */
    public function isBanned(): bool
    {
        return $this->banned_at !== null
            && ($this->banned_until === null || $this->banned_until->isFuture());
    }

    /** What a banned person is told: until when, and why. */
    public function banMessage(): string
    {
        $until = $this->banned_until
            ? 'until '.$this->banned_until->timezone(Restaurant::BUSINESS_TIMEZONE)->format('j M Y')
            : 'permanently';
        $reason = self::BAN_REASONS[$this->ban_reason] ?? null;

        return "Your account is banned {$until}".($reason ? " for {$reason}" : '').'.';
    }

    /** Ban reasons the admin picks from, with how they're shown. */
    public const BAN_REASONS = [
        'sexual' => 'nudity or sexual content',
        'spam' => 'spam or scams',
        'hate_harassment' => 'hate or harassment',
        'fake_account' => 'being a fake account',
    ];

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_user')
            ->using(RestaurantUser::class)
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    public function ownedRestaurants(): BelongsToMany
    {
        return $this->restaurants()
            ->wherePivot('role', 'owner')
            ->wherePivot('status', 'accepted');
    }

    /**
     * The restaurant this user is currently "switched into" managing (Page-switcher style).
     */
    public function activeRestaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'active_restaurant_id');
    }

    /**
     * Switch the user's active restaurant context. Only allowed for restaurants
     * the user is an accepted member of.
     */
    public function switchToRestaurant(int $restaurantId): bool
    {
        $isMember = $this->restaurants()
            ->where('restaurant_id', $restaurantId)
            ->wherePivot('status', 'accepted')
            ->exists();

        if (! $isMember) {
            return false;
        }

        $this->update(['active_restaurant_id' => $restaurantId]);

        return true;
    }

    public function pendingInvitations(): HasMany
    {
        return $this->hasMany(RestaurantInvitation::class, 'email', 'email');
    }

    /**
     * Check if user manages a specific restaurant.
     */
    public function managesRestaurant(int $restaurantId): bool
    {
        return $this->restaurants()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('restaurant_user.role', ['owner', 'manager'])
            ->wherePivot('status', 'accepted')
            ->exists();
    }

    /**
     * Check if user is the owner of a specific restaurant.
     */
    public function isRestaurantOwner(int $restaurantId): bool
    {
        return $this->restaurants()
            ->where('restaurant_id', $restaurantId)
            ->wherePivot('role', 'owner')
            ->wherePivot('status', 'accepted')
            ->exists();
    }

    /**
     * Follow rows where this user is the one doing the following — personal
     * ones and those made as any of their restaurants.
     */
    public function following(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    /**
     * Only the follows made as the person themself (their "Following").
     */
    public function personalFollowing(): HasMany
    {
        return $this->following()->whereNull('follower_restaurant_id');
    }

    /**
     * Follows made as whichever profile the user is acting as right now:
     * the active restaurant's, or their own. Following, unfollowing and
     * "am I following?" all go through this.
     */
    public function actorFollowing(): HasMany
    {
        return $this->active_restaurant_id
            ? $this->following()->where('follower_restaurant_id', $this->active_restaurant_id)
            : $this->personalFollowing();
    }

    /**
     * People following this user (this user as the followable target).
     */
    public function followers(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    public function isFollowing(Model $followable): bool
    {
        return $this->actorFollowing()
            ->where('followable_id', $followable->getKey())
            ->where('followable_type', $followable->getMorphClass())
            ->exists();
    }

    public function follow(Model $followable): void
    {
        $this->following()->firstOrCreate([
            'follower_restaurant_id' => $this->active_restaurant_id,
            'followable_id' => $followable->getKey(),
            'followable_type' => $followable->getMorphClass(),
        ]);
    }

    public function unfollow(Model $followable): void
    {
        $this->actorFollowing()
            ->where('followable_id', $followable->getKey())
            ->where('followable_type', $followable->getMorphClass())
            ->delete();
    }

    /**
     * IDs of users followed by the profile this user is acting as.
     */
    public function followingUserIds(): array
    {
        return $this->actorFollowing()->where('followable_type', User::class)->pluck('followable_id')->all();
    }

    /**
     * IDs of restaurants followed by the profile this user is acting as.
     */
    public function followingRestaurantIds(): array
    {
        return $this->actorFollowing()->where('followable_type', Restaurant::class)->pluck('followable_id')->all();
    }

    /**
     * The actual User models following this user (unwraps the Follow pivot
     * rows `followers()` returns) — for fanning out notifications to them.
     */
    public function followerUsers(): Collection
    {
        return $this->followers()->with('follower')->get()->pluck('follower')->filter()->values();
    }

    /**
     * Registered push-notification device tokens (one per installed device).
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Saved addresses (Menu → Address in the app).
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }
}
