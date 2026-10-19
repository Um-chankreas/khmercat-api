<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person's rating + comment on a restaurant (one each — posting again
 * updates it), shown on the restaurant's Reviews tab. Optionally linked to
 * the review video they posted with it, and answered by the restaurant.
 */
class RestaurantReview extends Model
{
    protected $fillable = [
        'restaurant_id',
        'user_id',
        'rating',
        'comment',
        'video_review_id',
        'reply',
        'replied_by',
        'replied_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'replied_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Soft-deleted videos drop out, so the review shows without one. */
    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoReview::class, 'video_review_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(RestaurantReviewLike::class);
    }

    /**
     * The person's review of the restaurant — created, or updated with the
     * new rating/comment. A new [videoId] replaces the linked video; null
     * keeps whichever one it already had.
     */
    public static function upsertFor(int $restaurantId, int $userId, int $rating, ?string $comment, ?int $videoId = null): self
    {
        $review = self::firstOrNew(['restaurant_id' => $restaurantId, 'user_id' => $userId]);
        $review->rating = $rating;
        $review->comment = $comment;
        if ($videoId !== null) {
            $review->video_review_id = $videoId;
        }
        $review->save();

        return $review;
    }

    /**
     * The caption of a review video as review text: inline #tags belong to
     * the video, not the written review.
     */
    public static function commentFromCaption(?string $caption): ?string
    {
        if ($caption === null) {
            return null;
        }
        $text = trim(preg_replace('/#[\p{L}\p{N}\p{M}_]+/u', '', $caption));

        return $text === '' ? null : $text;
    }
}
