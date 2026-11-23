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
        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 30)->nullable()->after('phone');
            $table->string('profile_photo_path')->nullable()->after('gender');
            $table->boolean('must_change_password')->default(false)->after('profile_photo_path');
            $table->unsignedTinyInteger('satisfaction_score')->nullable()->after('must_change_password');
            $table->unsignedTinyInteger('performance_score')->nullable()->after('satisfaction_score');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('published_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gender', 'profile_photo_path', 'must_change_password', 'satisfaction_score', 'performance_score']);
        });
    }
};
