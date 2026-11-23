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
        Schema::create('salary_structures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->char('country_code', 2);
            $table->char('currency', 3);
            $table->string('frequency', 16);
            $table->decimal('basic_salary', 14, 2);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('salary_structure_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('component_type', 16);
            $table->string('calculation_method', 24);
            $table->decimal('value', 14, 4);
            $table->boolean('is_taxable')->default(false);
            $table->boolean('is_statutory')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['salary_structure_id', 'component_type']);
        });

        Schema::create('employee_salary_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('salary_structure_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from', 'effective_to']);
        });

        Schema::create('payroll_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('country_code', 2);
            $table->char('currency', 3);
            $table->string('frequency', 16);
            $table->string('period_key', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('pay_on');
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('gross_total', 16, 2)->default(0);
            $table->decimal('deductions_total', 16, 2)->default(0);
            $table->decimal('net_total', 16, 2)->default(0);
            $table->string('payment_reference', 180)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['country_code', 'currency', 'period_key']);
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 24);
            $table->string('line_type', 16);
            $table->string('description', 180);
            $table->decimal('amount', 14, 2);
            $table->boolean('is_taxable')->default(false);
            $table->timestamps();
            $table->index(['payroll_period_id', 'employee_id']);
        });

        Schema::create('payroll_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('salary_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_number_snapshot', 80);
            $table->string('employee_name_snapshot', 255);
            $table->char('currency', 3);
            $table->decimal('basic_amount', 14, 2);
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('deductions_amount', 14, 2);
            $table->decimal('benefits_amount', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->timestamps();
            $table->unique(['payroll_period_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::create('payroll_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_structure_component_id')->nullable()->constrained('salary_structure_components')->nullOnDelete();
            $table->foreignId('payroll_adjustment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 180);
            $table->string('line_type', 16);
            $table->string('calculation_method', 24)->nullable();
            $table->decimal('calculation_base', 14, 2)->nullable();
            $table->decimal('rate_percent', 9, 4)->nullable();
            $table->decimal('amount', 14, 2);
            $table->boolean('is_taxable')->default(false);
            $table->boolean('is_statutory')->default(false);
            $table->timestamps();
            $table->index(['payroll_entry_id', 'line_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_entry_lines');
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('employee_salary_assignments');
        Schema::dropIfExists('salary_structure_components');
        Schema::dropIfExists('salary_structures');
    }
};
