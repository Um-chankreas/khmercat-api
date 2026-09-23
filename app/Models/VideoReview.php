<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoReview extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'restaurant_id',
        'type',
        'caption',
        'rating',
        'video_url',
        'thumbnail_url',
        'original_size',
        'compressed_size',
        'compression_ratio',
        'aspect_ratio',
        'status',
    ];

    /**
     * Type Constants.
     */
    public const TYPE_REVIEW = 'review';

    public const TYPE_RESTAURANT_POST = 'restaurant_post';

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'original_size_formatted',
        'compressed_size_formatted',
        'space_saved_percentage',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'aspect_ratio' => 'float',
        'original_size' => 'integer',
        'compressed_size' => 'integer',
        'rating' => 'integer',
    ];

    /**
     * Get the formatted human-readable original file size.
     */
    protected function originalSizeFormatted(): Attribute
    {
        return Attribute::get(fn () => $this->formatBytes($this->original_size));
    }

    /**
     * Get the formatted human-readable compressed file size.
     */
    protected function compressedSizeFormatted(): Attribute
    {
        return Attribute::get(fn () => $this->formatBytes($this->compressed_size));
    }

    /**
     * Get the percentage of space saved after compression.
     */
    protected function spaceSavedPercentage(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->original_size || $this->original_size === 0) {
                return '0%';
            }

            $saved = (($this->original_size - $this->compressed_size) / $this->original_size) * 100;

            return round(max(0, $saved), 1).'%';
        });
    }

    /**
     * Helper function to convert bytes into readable string format.
     */
    private function formatBytes(?int $bytes, int $precision = 2): string
    {
        if (! $bytes || $bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }

    /**
     * Get the user who created this video review.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the restaurant this video is about (review target or poster).
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(VideoLike::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(VideoSave::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(VideoComment::class)->latest();
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'hashtag_video')->withTimestamps();
    }

    /**
     * Sync this video's hashtags from two sources: a dedicated hashtags
     * input (comma/space separated, e.g. "khmerfood, delicious" or
     * "#khmerfood #delicious") and any #tags typed inline in the caption —
     * unioned together so either input style works. Normalizes to
     * lowercase and creates any hashtag rows that don't exist yet.
     */
    public function syncHashtags(?string $explicitHashtags = null): void
    {
        $names = collect();

        if ($this->caption) {
            // \p{M} (combining marks) is required alongside \p{L} — Khmer
            // (and many other scripts) build a single character out of a
            // base letter plus combining vowel/diacritic marks, which \p{L}
            // alone excludes. Without it, #ម្ហូបខ្មែរ truncates after the
            // first base letter instead of capturing the whole word.
            preg_match_all('/#([\p{L}\p{N}\p{M}_]+)/u', $this->caption, $matches);
            $names = $names->merge($matches[1]);
        }

        if ($explicitHashtags) {
            $parts = preg_split('/[\s,]+/u', trim($explicitHashtags), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $part) {
                $part = ltrim($part, '#');
                if ($part !== '') {
                    $names->push($part);
                }
            }
        }

        $names = $names->map(fn ($name) => mb_strtolower($name))->unique()->values();

        if ($names->isEmpty()) {
            $this->hashtags()->sync([]);

            return;
        }

        $hashtagIds = $names->map(
            fn ($name) => Hashtag::firstOrCreate(['name' => $name])->id
        );

        $this->hashtags()->sync($hashtagIds);
    }
}
