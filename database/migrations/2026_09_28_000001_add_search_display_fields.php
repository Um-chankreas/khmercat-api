<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the search results screen displays but nothing stored yet:
 * restaurant price level / service type / delivery time / opening hours /
 * sponsored flag, video duration + view count, and a verified badge for users.
 * Everything is nullable (or defaulted) so existing rows stay valid and the
 * app simply hides a field that hasn't been filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            // 1 = $, 2 = $$, 3 = $$$, 4 = $$$$
            $table->unsignedTinyInteger('price_level')->nullable()->after('address');
            // dine_in | delivery | dine_in_delivery | takeaway
            $table->string('service_type', 20)->nullable()->after('price_level');
            $table->unsignedSmallInteger('delivery_time_min')->nullable()->after('service_type');
            $table->unsignedSmallInteger('delivery_time_max')->nullable()->after('delivery_time_min');
            // Local (Asia/Phnom_Penh) wall-clock hours; closing < opening means it closes after midnight.
            $table->time('opening_time')->nullable()->after('delivery_time_max');
            $table->time('closing_time')->nullable()->after('opening_time');
            $table->boolean('is_sponsored')->default(false)->after('status');
        });

        Schema::table('video_reviews', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable()->after('aspect_ratio');
            $table->unsignedInteger('views_count')->default(0)->after('duration_seconds');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'price_level',
                'service_type',
                'delivery_time_min',
                'delivery_time_max',
                'opening_time',
                'closing_time',
                'is_sponsored',
            ]);
        });

        Schema::table('video_reviews', function (Blueprint $table) {
            $table->dropColumn(['duration_seconds', 'views_count']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_verified');
        });
    }
};
