<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RestaurantUser extends Pivot
{
    /**
     * Define the pivot table name explicitly.
     */
    protected $table = 'restaurant_user';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    protected $fillable = [
        'restaurant_id',
        'user_id',
        'role',   // 'owner', 'manager', 'staff'
        'status', // 'pending', 'accepted', 'rejected'
    ];

    /**
     * Role Constants for consistent code execution.
     */
    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_STAFF = 'staff';

    /**
     * Status Constants.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    // --- Relationships ---

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
