<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_salary_assignments', function (Blueprint $table): void {
            $table->string('salary_grade', 60)->nullable();
            $table->string('salary_scale', 60)->nullable();
            $table->string('salary_step', 60)->nullable();
        });

        Schema::table('salary_structure_components', function (Blueprint $table): void {
            $table->string('code', 80)->nullable();
            $table->boolean('is_pensionable')->default(false);
            $table->boolean('is_recurring')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->index(['salary_structure_id', 'code']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::table('payroll_adjustments', function (Blueprint $table): void {
            $table->boolean('is_pensionable')->default(false);
        });

        Schema::table('payroll_entry_lines', function (Blueprint $table): void {
            $table->decimal('calculation_hours', 8, 2)->nullable();
            $table->decimal('calculation_multiplier', 6, 3)->nullable();
            $table->string('source_type', 24)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('component_code_snapshot', 80)->nullable();
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->json('overtime_rules_snapshot')->nullable();
        });

        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->string('department_snapshot', 120)->nullable();
            $table->string('job_title_snapshot', 120)->nullable();
            $table->text('tax_identifier_snapshot')->nullable();
            $table->string('tax_residency_snapshot', 20)->nullable();
            $table->string('pension_fund_snapshot', 20)->nullable();
        });

        Schema::table('leave_types', function (Blueprint $table): void {
            $table->string('payroll_treatment', 16)->default('paid');
            $table->decimal('payroll_paid_fraction', 5, 4)->default(1);
        });
        DB::table('leave_types')->where('name', 'Unpaid leave')->update([
            'payroll_treatment' => 'unpaid',
            'payroll_paid_fraction' => 0,
        ]);

        Schema::create('payroll_overtime_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('country_code', 2);
            $table->char('currency', 3);
            $table->string('frequency', 16);
            $table->string('overtime_type', 16);
            $table->unsignedSmallInteger('version');
            $table->string('calculation_basis', 24);
            $table->decimal('hours_per_period', 8, 2);
            $table->decimal('multiplier', 6, 3);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_pensionable')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('authority_reference', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['country_code', 'currency', 'frequency', 'overtime_type', 'version']);
            $table->index(['country_code', 'currency', 'frequency', 'overtime_type', 'is_active', 'effective_from'], 'payroll_overtime_rules_lookup_index');
        });

        Schema::create('payroll_overtime_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payroll_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('payroll_overtime_rules')->restrictOnDelete();
            $table->date('worked_on');
            $table->string('overtime_type', 16);
            $table->decimal('hours', 8, 2);
            $table->decimal('hourly_rate', 14, 4)->nullable();
            $table->decimal('multiplier', 6, 3)->nullable();
            $table->decimal('calculated_amount', 14, 2)->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'worked_on']);
            $table->index(['payroll_period_id', 'employee_id']);
        });

        Schema::create('employee_payroll_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('loan_type', 16);
            $table->char('currency', 3);
            $table->decimal('principal_amount', 14, 2);
            $table->decimal('total_repayable', 14, 2)->nullable();
            $table->decimal('installment_amount', 14, 2)->nullable();
            $table->date('repayment_starts_on')->nullable();
            $table->string('terms_reference', 180)->nullable();
            $table->string('disbursement_reference', 180)->nullable();
            $table->timestampTz('disbursed_at')->nullable();
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'repayment_starts_on'], 'employee_payroll_loans_employee_status_date_index');
        });

        Schema::create('payroll_loan_repayments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_payroll_loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_entry_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('status', 16)->default('scheduled');
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_payroll_loan_id', 'payroll_period_id'], 'payroll_loan_repayments_loan_period_unique');
            $table->index(['payroll_period_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_loan_repayments');
        Schema::dropIfExists('employee_payroll_loans');
        Schema::dropIfExists('payroll_overtime_records');
        Schema::dropIfExists('payroll_overtime_rules');

        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn(['payroll_treatment', 'payroll_paid_fraction']);
        });

        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->dropColumn(['department_snapshot', 'job_title_snapshot', 'tax_identifier_snapshot', 'tax_residency_snapshot', 'pension_fund_snapshot']);
        });

        Schema::table('payroll_entry_lines', function (Blueprint $table): void {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['calculation_hours', 'calculation_multiplier', 'source_type', 'source_id', 'component_code_snapshot']);
        });

        Schema::table('payroll_adjustments', function (Blueprint $table): void {
            $table->dropColumn('is_pensionable');
        });

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropColumn('overtime_rules_snapshot');
        });

        Schema::table('salary_structure_components', function (Blueprint $table): void {
            $table->dropIndex(['salary_structure_id', 'code']);
            $table->dropIndex(['effective_from', 'effective_to']);
            $table->dropColumn(['code', 'is_pensionable', 'is_recurring', 'effective_from', 'effective_to']);
        });

        Schema::table('employee_salary_assignments', function (Blueprint $table): void {
            $table->dropColumn(['salary_grade', 'salary_scale', 'salary_step']);
        });
    }
};
