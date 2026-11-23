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
        if (! Schema::hasTable('shift_assignments')) {
            Schema::create('shift_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('work_shift_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->date('effective_from');
                $table->date('effective_until')->nullable();
                $table->timestamps();
                $table->index(['employee_id', 'effective_from', 'effective_until']);
            });

            return;
        }

        Schema::table('shift_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('shift_assignments', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('shift_assignments', 'assigned_by')) {
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('shift_assignments')) {
            return;
        }

        Schema::table('shift_assignments', function (Blueprint $table): void {
            foreach (['branch_id', 'assigned_by'] as $column) {
                if (Schema::hasColumn('shift_assignments', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });
    }
};
