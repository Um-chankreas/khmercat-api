<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A follow made while the user is acting as one of their restaurants
 * belongs to that restaurant (its "Following"), not to the person. The
 * person who tapped Follow stays in `follower_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->foreignId('follower_restaurant_id')
                ->nullable()
                ->after('follower_id')
                ->constrained('restaurants')
                ->cascadeOnDelete();
        });

        Schema::table('follows', function (Blueprint $table) {
            // The same person may now follow someone both personally and
            // as their restaurant. Add the wider unique index before
            // dropping the old one: the follower_id foreign key needs an
            // index starting with that column at all times.
            $table->unique(
                ['follower_id', 'follower_restaurant_id', 'followable_id', 'followable_type'],
                'follows_actor_followable_unique'
            );
            $table->dropUnique(['follower_id', 'followable_id', 'followable_type']);
        });
    }

    public function down(): void
    {
        // Restaurant follows have no place in the old shape.
        DB::table('follows')->whereNotNull('follower_restaurant_id')->delete();

        Schema::table('follows', function (Blueprint $table) {
            $table->unique(['follower_id', 'followable_id', 'followable_type']);
            $table->dropUnique('follows_actor_followable_unique');
            $table->dropConstrainedForeignId('follower_restaurant_id');
        });
    }
};
