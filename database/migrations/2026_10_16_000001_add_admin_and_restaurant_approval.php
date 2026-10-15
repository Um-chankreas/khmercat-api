<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admins (`users.is_admin`) approve restaurants before they're public.
 * Restaurants already live when this ships are approved here, so none of
 * them disappear; new ones start `pending` and stay hidden until approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('is_verified');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('rejection_reason', 500)->nullable()->after('approved_at');
        });

        DB::table('restaurants')
            ->where('status', 'pending')
            ->update(['status' => 'approved', 'approved_at' => now()]);
    }

    public function down(): void
    {
        DB::table('restaurants')
            ->where('status', 'approved')
            ->update(['status' => 'pending']);

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'rejection_reason']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
