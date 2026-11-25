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
            $table->text('tax_identifier')->nullable();
            $table->text('pension_member_number')->nullable();
            $table->text('nhif_member_number')->nullable();
            $table->text('heslb_account_number')->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->text('bank_account_number')->nullable();
        });

        Schema::table('employee_salary_assignments', function (Blueprint $table): void {
            $table->text('change_reason')->nullable();
        });

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
        });

        Schema::create('payroll_statutory_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 16);
            $table->string('jurisdiction', 16);
            $table->unsignedSmallInteger('version');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('employee_rate', 9, 4)->nullable();
            $table->decimal('employer_rate', 9, 4)->nullable();
            $table->string('calculation_basis', 24)->nullable();
            $table->decimal('threshold', 14, 2)->nullable();
            $table->decimal('ceiling', 14, 2)->nullable();
            $table->json('parameters')->nullable();
            $table->string('authority_reference', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['code', 'jurisdiction', 'version']);
            $table->index(['code', 'jurisdiction', 'is_active', 'effective_from']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->json('statutory_rules_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropColumn(['statutory_rules_snapshot', 'closed_by', 'closed_at']);
        });

        Schema::dropIfExists('payroll_statutory_rules');

        Schema::table('employee_salary_assignments', function (Blueprint $table): void {
            $table->dropColumn('change_reason');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_identifier', 'pension_member_number', 'nhif_member_number', 'heslb_account_number',
                'bank_name', 'bank_branch', 'bank_account_number',
            ]);
        });
    }
};
