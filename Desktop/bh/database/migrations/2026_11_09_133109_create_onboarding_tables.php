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
        Schema::create('onboarding_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_on');
            $table->string('status', 20)->default('in_progress')->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('onboarding_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->date('due_on')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();
            $table->index(['onboarding_case_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_cases');
    }
};
