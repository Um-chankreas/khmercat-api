<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant's menu, as photos of its pages, in display order. Shown in
 * the app and on the public web page its QR code points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_menu_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('url');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_menu_images');
    }
};
