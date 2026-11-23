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
            $table->string('phone', 60)->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 60)->nullable();
            $table->string('emergency_contact_relationship', 80)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('employment_type', 40)->nullable()->index();
            $table->date('joined_on')->nullable()->index();
            $table->date('probation_ends_on')->nullable();
            $table->date('contract_starts_on')->nullable();
            $table->date('contract_ends_on')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['employment_type']);
            $table->dropIndex(['joined_on']);
            $table->dropIndex(['contract_ends_on']);
            $table->dropColumn([
                'phone', 'address', 'date_of_birth', 'emergency_contact_name', 'emergency_contact_phone',
                'emergency_contact_relationship', 'job_title', 'employment_type', 'joined_on', 'probation_ends_on',
                'contract_starts_on', 'contract_ends_on',
            ]);
        });
    }
};
