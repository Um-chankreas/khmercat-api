<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holiday posters: a restaurant's notice for one date (closed, special
     * hours or open), shown on its profile, in its open/closed status that
     * day, and as a `holiday_poster` item in the feed.
     */
    public function up(): void
    {
        Schema::create('restaurant_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->string('title', 80);
            // A key from the app's Cambodia holiday list, null for a custom day.
            $table->string('holiday_key', 40)->nullable();
            $table->enum('mode', ['closed', 'special', 'open'])->default('closed');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            // Its post in the feed.
            $table->foreignId('video_review_id')->nullable()->constrained('video_reviews')->nullOnDelete();
            $table->timestamps();

            // One notice per restaurant per day: posting again updates it.
            $table->unique(['restaurant_id', 'date']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE video_reviews MODIFY type ENUM('review','restaurant_post','holiday_poster') NOT NULL DEFAULT 'review'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_holidays');

        if (DB::getDriverName() === 'mysql') {
            DB::table('video_reviews')->where('type', 'holiday_poster')->delete();
            DB::statement("ALTER TABLE video_reviews MODIFY type ENUM('review','restaurant_post') NOT NULL DEFAULT 'review'");
        }
    }
};
