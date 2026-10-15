<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's report of a video, for admins to review.
     */
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('video_review_id')->constrained('video_reviews')->cascadeOnDelete();
            $table->string('reason', 50);
            $table->string('note', 500)->nullable();
            // open → removed (the video was taken down) or dismissed.
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // One open report per person per video is enough.
            $table->index(['video_review_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
