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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('probation_review_status', 24)->default('pending')->index();
            $table->text('probation_review_note')->nullable();
            $table->timestampTz('probation_reviewed_at')->nullable();
            $table->foreignId('probation_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['probation_reviewed_by']);
            $table->dropIndex(['probation_review_status']);
            $table->dropColumn(['probation_review_status', 'probation_review_note', 'probation_reviewed_at', 'probation_reviewed_by']);
        });
    }
};
