<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `approved_by` was never made nullable in the migration that added it,
     * even though RestaurantController::create() always inserts a new
     * restaurant with `approved_by => null` (nothing's approved it yet —
     * status starts as 'pending'). Local dev's database had this patched
     * directly at some point, masking the bug there; a fresh migrate (e.g.
     * on the server) exposes it as a NOT NULL constraint violation.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable(false)->change();
        });
    }
};
