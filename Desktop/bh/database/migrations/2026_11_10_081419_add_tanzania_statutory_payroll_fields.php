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
            $table->json('payroll_profile')->nullable();
        });

        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->decimal('employer_contributions_amount', 14, 2)->default(0)->after('net_amount');
        });

        Schema::table('payroll_entry_lines', function (Blueprint $table): void {
            $table->string('line_type', 32)->change();
        });

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->decimal('employer_contributions_total', 16, 2)->default(0)->after('net_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropColumn('employer_contributions_total');
        });

        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->dropColumn('employer_contributions_amount');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('payroll_profile');
        });
    }
};
