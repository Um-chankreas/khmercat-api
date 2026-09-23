<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RestaurantInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'email',
        'role',
        'token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Automatically generate a secure unique invitation token on creation.
     */
    protected static function booted(): void
    {
        static::creating(function ($invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(40);
            }
            if (empty($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(7); // Valid for 7 days
            }
        });
    }

    // --- Relationships ---

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    // --- Helper Methods ---

    /**
     * Check if the invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
