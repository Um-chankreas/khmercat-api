<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A restaurant's Reviews tab: one rating per person per restaurant
     * (posting again updates it), an optional review video and the
     * restaurant's reply.
     */
    public function up(): void
    {
        Schema::create('restaurant_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->foreignId('video_review_id')->nullable()->constrained('video_reviews')->nullOnDelete();
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'user_id']);
            $table->index(['restaurant_id', 'id']);
        });

        Schema::create('restaurant_review_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['restaurant_review_id', 'user_id']);
        });

        $this->backfillFromReviewVideos();
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_review_likes');
        Schema::dropIfExists('restaurant_reviews');
    }

    /**
     * Rated review videos posted before this table existed become reviews:
     * each person's latest one per restaurant, its caption (minus #tags)
     * as the comment.
     */
    private function backfillFromReviewVideos(): void
    {
        $latestIds = DB::table('video_reviews')
            ->where('type', 'review')
            ->whereNotNull('restaurant_id')
            ->whereNotNull('rating')
            ->whereNull('deleted_at')
            ->groupBy('restaurant_id', 'user_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        foreach ($latestIds->chunk(500) as $chunk) {
            $rows = DB::table('video_reviews')->whereIn('id', $chunk)->get()->map(fn ($v) => [
                'restaurant_id' => $v->restaurant_id,
                'user_id' => $v->user_id,
                'rating' => max(1, min(5, (int) $v->rating)),
                'comment' => $this->stripHashtags($v->caption),
                'video_review_id' => $v->id,
                'created_at' => $v->created_at,
                'updated_at' => $v->updated_at,
            ]);
            DB::table('restaurant_reviews')->insert($rows->all());
        }
    }

    private function stripHashtags(?string $caption): ?string
    {
        if ($caption === null) {
            return null;
        }
        $text = trim(preg_replace('/#[\p{L}\p{N}\p{M}_]+/u', '', $caption));

        return $text === '' ? null : $text;
    }
};
