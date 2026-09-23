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
            $table->decimal('compression_ratio', 5, 2)->nullable()->after('compressed_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_reviews', function (Blueprint $table) {
            $table->dropColumn('compression_ratio');
        });
    }
};
