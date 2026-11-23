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
        Schema::create('offboarding_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 32)->index();
            $table->text('reason_details')->nullable();
            $table->date('notice_received_on')->nullable();
            $table->date('last_working_day')->index();
            $table->string('status', 24)->default('in_progress')->index();
            $table->string('exit_interview_status', 24)->default('not_scheduled');
            $table->timestampTz('exit_interview_at')->nullable();
            $table->text('exit_interview_note')->nullable();
            $table->string('final_payroll_status', 24)->default('pending')->index();
            $table->text('final_payroll_note')->nullable();
            $table->timestampTz('account_deactivated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['owner_id', 'status']);
        });

        Schema::create('offboarding_clearance_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offboarding_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->string('department', 120)->nullable();
            $table->text('instructions')->nullable();
            $table->date('due_on')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->timestampTz('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();
            $table->index(['offboarding_case_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offboarding_clearance_items');
        Schema::dropIfExists('offboarding_cases');
    }
};
