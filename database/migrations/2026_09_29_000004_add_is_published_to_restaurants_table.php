<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owners can hide their restaurant (unpublish) without deleting it: hidden
 * from search, the feed and its public page, visible only to its team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('is_published')->default(true)->after('status');
            $table->index('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['is_published']);
            $table->dropColumn('is_published');
        });
    }
};
