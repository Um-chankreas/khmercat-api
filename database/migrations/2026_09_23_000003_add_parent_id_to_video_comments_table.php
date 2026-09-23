<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal reply support — nullable so existing top-level comments are
     * unaffected. Needed so a "replied to your comment" notification has
     * something real to point at.
     */
    public function up(): void
    {
        Schema::table('video_comments', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('user_id')
                ->constrained('video_comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
