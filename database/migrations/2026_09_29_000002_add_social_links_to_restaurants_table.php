<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook / TikTok / Telegram for a restaurant — same shape as the users
 * table's social links, shown as buttons on the restaurant profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('facebook_url')->nullable()->after('phone');
            $table->string('tiktok_url')->nullable()->after('facebook_url');
            $table->string('telegram_username')->nullable()->after('tiktok_url');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['facebook_url', 'tiktok_url', 'telegram_username']);
        });
    }
};
