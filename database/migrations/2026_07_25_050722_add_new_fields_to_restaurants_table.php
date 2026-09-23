<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('owner_id')->after('id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->after('owner_id')->constrained('restaurant_categories')->nullOnDelete();

            // Basic Info
            $table->string('name')->after('category_id');
            $table->text('description')->nullable()->after('name');

            // Contact & Location
            $table->string('phone')->nullable()->after('description');
            $table->text('address')->nullable()->after('phone');

            // Media & Images
            $table->string('profile_picture')->nullable()->after('address');
            $table->string('profile_thumbnail')->nullable()->after('profile_picture');
            $table->string('cover_picture')->nullable()->after('profile_thumbnail');
            $table->string('cover_thumbnail')->nullable()->after('cover_picture');

            // Coordinates (Precision 10, Scale 8 handles standard GPS values)
            $table->decimal('latitude', 10, 8)->nullable()->after('cover_thumbnail');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');

            // Status (e.g., 'active', 'pending', 'closed')
            $table->string('status')->default('pending')->after('longitude');
            $table->foreignId('approved_by')->after('status')->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['category_id']);

            // Drop all added columns including soft deletes
            $table->dropColumn([
                'owner_id',
                'category_id',
                'name',
                'description',
                'phone',
                'address',
                'profile_picture',
                'profile_thumbnail',
                'cover_picture',
                'cover_thumbnail',
                'latitude',
                'longitude',
                'status',
                'approved_by',
            ]);
        });
    }
};
