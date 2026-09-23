<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The feed's core query is `WHERE status = 'ready' ORDER BY id DESC` —
     * composite (status, id) lets that filter+sort resolve from the index
     * alone instead of a full table scan once this table has real volume.
     */
    public function up(): void
    {
        Schema::table('video_reviews', function (Blueprint $table) {
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('video_reviews', function (Blueprint $table) {
            $table->dropIndex(['status', 'id']);
        });
    }
};
