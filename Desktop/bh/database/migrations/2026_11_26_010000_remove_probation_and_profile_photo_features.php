<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'probation_reviewed_by')) {
                $table->dropForeign(['probation_reviewed_by']);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'probation_review_status')) {
                $table->dropIndex(['probation_review_status']);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $columns = [
                'probation_ends_on',
                'probation_review_status',
                'probation_review_note',
                'probation_reviewed_at',
                'probation_reviewed_by',
                'profile_photo_path',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('probation_ends_on')->nullable();
            $table->string('probation_review_status', 24)->default('pending');
            $table->text('probation_review_note')->nullable();
            $table->timestampTz('probation_reviewed_at')->nullable();
            $table->foreignId('probation_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('profile_photo_path')->nullable();
            $table->index('probation_review_status');
        });
    }
};
