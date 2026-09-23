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
        'facebook_url',
        'tiktok_url',
        'telegram_username',
        'email_otp',
        'email_otp_expires_at',
        'email_verified_at',
        'active_restaurant_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'password' => 'hashed',
        ];
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
     * Follow rows where this user is the one doing the following.
     */
    public function following(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
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
        return $this->following()
            ->where('followable_id', $followable->getKey())
            ->where('followable_type', $followable->getMorphClass())
            ->exists();
    }

    public function follow(Model $followable): void
    {
        $this->following()->firstOrCreate([
            'followable_id' => $followable->getKey(),
            'followable_type' => $followable->getMorphClass(),
        ]);
    }

    public function unfollow(Model $followable): void
    {
        $this->following()
            ->where('followable_id', $followable->getKey())
            ->where('followable_type', $followable->getMorphClass())
            ->delete();
    }

    /**
     * IDs of users this user follows.
     */
    public function followingUserIds(): array
    {
        return $this->following()->where('followable_type', User::class)->pluck('followable_id')->all();
    }

    /**
     * IDs of restaurants this user follows.
     */
    public function followingRestaurantIds(): array
    {
        return $this->following()->where('followable_type', Restaurant::class)->pluck('followable_id')->all();
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
}
