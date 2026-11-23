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
        Schema::create('onboarding_document_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_document_id')->nullable()->unique()->constrained('employee_documents')->nullOnDelete();
            $table->string('category', 100);
            $table->text('instructions')->nullable();
            $table->date('due_on')->nullable()->index();
            $table->string('status', 20)->default('requested')->index();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['onboarding_case_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_document_requests');
    }
};
