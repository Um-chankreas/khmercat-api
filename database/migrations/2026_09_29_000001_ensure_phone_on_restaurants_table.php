<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `phone` is created by 2026_07_25_050722, but some local databases ended up
 * without it, which made saving the Edit restaurant form fail with
 * "Unknown column 'phone'". Adds it only where it's missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('restaurants', 'phone')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->string('phone')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: the column belongs to the original migration.
    }
};
