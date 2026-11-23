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
        Schema::create('job_vacancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->string('slug')->unique();
            $table->string('department', 120)->nullable()->index();
            $table->string('employment_type', 40)->nullable();
            $table->string('location', 180)->nullable();
            $table->text('summary');
            $table->longText('description');
            $table->longText('requirements')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->date('closes_on')->nullable()->index();
            $table->timestamps();
            $table->index(['status', 'closes_on']);
        });

        Schema::create('job_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_vacancy_id')->constrained()->restrictOnDelete();
            $table->string('applicant_name', 180);
            $table->string('email')->index();
            $table->string('phone', 60)->nullable();
            $table->text('cover_letter')->nullable();
            $table->string('resume_path')->unique();
            $table->string('resume_filename');
            $table->string('resume_mime_type', 100);
            $table->unsignedBigInteger('resume_size');
            $table->string('resume_sha256', 64);
            $table->string('stage', 24)->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['job_vacancy_id', 'email']);
            $table->index(['stage', 'created_at']);
        });

        Schema::create('job_application_stage_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_stage', 24)->nullable();
            $table->string('to_stage', 24);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['job_application_id', 'created_at']);
        });

        Schema::create('recruitment_interviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('location', 180)->nullable();
            $table->string('meeting_url', 500)->nullable();
            $table->string('status', 20)->default('scheduled')->index();
            $table->text('feedback')->nullable();
            $table->string('outcome', 20)->default('pending');
            $table->timestamps();
            $table->index(['job_application_id', 'starts_at']);
        });

        Schema::create('recruitment_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('letter_path')->unique();
            $table->string('letter_filename');
            $table->string('letter_sha256', 64);
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->string('status', 20)->default('issued')->index();
            $table->timestampTz('responded_at')->nullable();
            $table->text('response_note')->nullable();
            $table->timestamps();
            $table->index(['job_application_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_offers');
        Schema::dropIfExists('recruitment_interviews');
        Schema::dropIfExists('job_application_stage_changes');
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('job_vacancies');
    }
};
