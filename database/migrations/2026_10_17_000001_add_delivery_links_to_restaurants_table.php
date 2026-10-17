<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The food-delivery apps the restaurant is listed on, as
     * [{name, url}] where name is one of Restaurant::DELIVERY_APPS —
     * shown as order buttons on the restaurant profile.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('delivery_links')->nullable()->after('telegram_username');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('delivery_links');
        });
    }
};
