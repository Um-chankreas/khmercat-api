<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hashtags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // normalized (lowercase), without the leading '#'
            $table->timestamps();
        });

        Schema::create('hashtag_video', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hashtag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_review_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['hashtag_id', 'video_review_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hashtag_video');
        Schema::dropIfExists('hashtags');
    }
};
