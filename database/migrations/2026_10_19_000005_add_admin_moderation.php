<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin moderation: banning people (for a while or for good),
     * deactivating restaurants, and a log of what each admin did.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable();
            // Null with `banned_at` set: banned for good.
            $table->timestamp('banned_until')->nullable();
            $table->string('ban_reason', 40)->nullable();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason', 500)->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            // approve / reject / deactivate / activate / remove_video /
            // dismiss_reports / ban / unban
            $table->string('action', 40);
            $table->string('target_type', 20); // restaurant / user / video
            $table->unsignedBigInteger('target_id');
            // Who/what it was about, as it read at the time.
            $table->string('summary');
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['deactivated_at', 'deactivation_reason']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banned_by');
            $table->dropColumn(['banned_at', 'banned_until', 'ban_reason']);
        });
    }
};
