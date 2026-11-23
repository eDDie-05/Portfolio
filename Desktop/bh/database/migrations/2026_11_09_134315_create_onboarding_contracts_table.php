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
        Schema::create('onboarding_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_case_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_document_id')->nullable()->unique()->constrained('employee_documents')->nullOnDelete();
            $table->string('original_path')->unique();
            $table->string('original_filename');
            $table->string('original_sha256', 64);
            $table->unsignedBigInteger('original_size');
            $table->string('status', 20)->default('sent')->index();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->text('response_note')->nullable();
            $table->timestamps();
            $table->unique(['onboarding_case_id', 'version']);
            $table->index(['onboarding_case_id', 'created_at']);
            $table->index(['status', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_contracts');
    }
};
