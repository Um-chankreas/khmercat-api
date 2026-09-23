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
        Schema::table('video_reviews', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->nullable()->after('user_id')
                ->constrained()->cascadeOnDelete();
            $table->enum('type', ['review', 'restaurant_post'])->default('review')->after('restaurant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_reviews', function (Blueprint $table) {
            $table->dropForeign(['restaurant_id']);
            $table->dropColumn(['restaurant_id', 'type']);
        });
    }
};
