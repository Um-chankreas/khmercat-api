<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A notice can span several days: `date` is the first, `end_date` the
     * last (the same day for a one-day notice).
     */
    public function up(): void
    {
        Schema::table('restaurant_holidays', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('date');
        });
        DB::table('restaurant_holidays')->update(['end_date' => DB::raw('date')]);
        Schema::table('restaurant_holidays', function (Blueprint $table) {
            $table->date('end_date')->nullable(false)->change();
            $table->index(['restaurant_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_holidays', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'end_date']);
            $table->dropColumn('end_date');
        });
    }
};
