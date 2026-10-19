<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A restaurant's holiday notice from `date` to `end_date` (one or more
 * days): closed, special hours, or open as usual. Shown as a poster on its profile until the day has passed,
 * overrides its open/closed status that day, and is posted to the feed.
 */
class RestaurantHoliday extends Model
{
    public const MODE_CLOSED = 'closed';

    public const MODE_SPECIAL = 'special';

    public const MODE_OPEN = 'open';

    protected $fillable = [
        'restaurant_id',
        'created_by',
        'date',
        'end_date',
        'title',
        'holiday_key',
        'mode',
        'opens_at',
        'closes_at',
        'description',
        'image_path',
        'video_review_id',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    protected $hidden = ['image_path'];

    protected $appends = ['image_url'];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function feedPost(): BelongsTo
    {
        return $this->belongsTo(VideoReview::class, 'video_review_id');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null);
    }

    /** The business day ("today" in Phnom Penh), as Y-m-d. */
    public static function today(): string
    {
        return now(Restaurant::BUSINESS_TIMEZONE)->toDateString();
    }

    /**
     * Whether the restaurant is open at [time] (H:i:s) on this day; null
     * means "as usual" (fall back to its normal hours).
     */
    public function isOpenAt(string $time): ?bool
    {
        return match ($this->mode) {
            self::MODE_CLOSED => false,
            self::MODE_SPECIAL => $this->opens_at && $this->closes_at
                ? self::within($time, substr($this->opens_at, 0, 8), substr($this->closes_at, 0, 8))
                : false,
            default => null,
        };
    }

    /** Same open/close rules as the normal hours (overnight, 24h). */
    public static function within(string $now, string $open, string $close): bool
    {
        if ($open === $close) {
            return true;
        }

        return $open < $close
            ? $now >= $open && $now < $close
            : $now >= $open || $now < $close;
    }
}
