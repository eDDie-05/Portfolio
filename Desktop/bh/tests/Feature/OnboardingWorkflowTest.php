<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Models\OnboardingCase;
use App\Models\OnboardingContract;
use App\Models\OnboardingDocumentRequest;
use App\Models\OnboardingTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_starts_onboarding_and_assignees_complete_tasks_until_the_case_is_complete(): void
    {
        $hr = $this->user('onboarding.hr@example.test', 'system_admin');
        $employee = $this->user('onboarding.employee@example.test', 'employee');
        $assignee = $this->user('onboarding.assignee@example.test', 'hr_manager');
        $application = $this->application('hired');

        $this->actingAs($hr)->get('/recruitment/applications/'.$application->id)->assertOk()->assertSee('Start onboarding');
        $this->post('/recruitment/applications/'.$application->id.'/onboarding', [
            'employee_id' => $employee->id,
            'assigned_to' => $hr->id,
            'start_on' => now()->addDays(5)->toDateString(),
        ])->assertRedirect();
        $case = OnboardingCase::firstOrFail();
        $this->assertSame('in_progress', $case->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.case_created', 'subject_id' => $case->id]);
        $this->assertDatabaseCount('onboarding_cases', 1);

        $this->post('/onboarding/'.$case->id.'/tasks', [
            'title' => 'Prepare laptop',
            'description' => 'Set up the workstation.',
            'assigned_to' => $assignee->id,
            'due_on' => now()->addDays(4)->toDateString(),
        ])->assertRedirect();
        $task = OnboardingTask::firstOrFail();

        $this->actingAs($employee)->get('/my-onboarding-tasks')->assertOk()->assertDontSee('Prepare laptop');
        $this->actingAs($assignee)->get('/my-onboarding-tasks')->assertOk()->assertSee('Prepare laptop');
        $this->actingAs($assignee)->post('/onboarding/tasks/'.$task->id.'/complete', ['completion_note' => 'Laptop configured'])->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('completed', $case->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.task_completed', 'subject_id' => $task->id]);
    }

    public function test_onboarding_is_only_started_for_hired_applications_and_private_cases_are_scoped(): void
    {
        $hr = $this->user('onboarding.scope-hr@example.test', 'system_admin');
        $otherHr = $this->user('onboarding.scope-other@example.test', 'hr_manager');
        $employee = $this->user('onboarding.scope-employee@example.test', 'employee');
        $notHired = $this->application('interview');
        $this->actingAs($hr)->get('/recruitment/applications/'.$notHired->id.'/onboarding')->assertNotFound();
        $this->post('/recruitment/applications/'.$notHired->id.'/onboarding', [
            'employee_id' => $employee->id,
            'assigned_to' => $hr->id,
            'start_on' => now()->toDateString(),
        ])->assertNotFound();

        $application = $this->application('hired');
        $this->post('/recruitment/applications/'.$application->id.'/onboarding', [
            'employee_id' => $employee->id,
            'assigned_to' => $hr->id,
            'start_on' => now()->toDateString(),
        ])->assertRedirect();
        $case = OnboardingCase::firstOrFail();
        $this->actingAs($otherHr)->get('/onboarding/'.$case->id)->assertForbidden();
        $this->actingAs($employee)->get('/onboarding/'.$case->id)->assertForbidden();
        $this->actingAs($hr)->post('/recruitment/applications/'.$application->id.'/onboarding', [
            'employee_id' => $employee->id,
            'assigned_to' => $hr->id,
            'start_on' => now()->toDateString(),
        ])->assertSessionHasErrors('employee_id');
        $this->assertDatabaseCount('onboarding_cases', 1);
    }

    public function test_hr_can_request_documents_and_employee_can_submit_only_their_own_private_upload(): void
    {
        Storage::fake('local');
        $hr = $this->user('onboarding.docs-hr@example.test', 'system_admin');
        $employee = $this->user('onboarding.docs-employee@example.test', 'employee');
        $otherEmployee = $this->user('onboarding.docs-other@example.test', 'employee');
        $application = $this->application('hired');
        $this->actingAs($hr)->post('/recruitment/applications/'.$application->id.'/onboarding', [
            'employee_id' => $employee->id,
            'assigned_to' => $hr->id,
            'start_on' => now()->toDateString(),
        ])->assertRedirect();
        $case = OnboardingCase::firstOrFail();

        $this->post('/onboarding/'.$case->id.'/document-requests', [
            'category' => 'National identity card',
            'instructions' => 'Upload a clear scan of both sides.',
            'due_on' => now()->addDays(3)->toDateString(),
        ])->assertRedirect();
        $documentRequest = OnboardingDocumentRequest::firstOrFail();
        $this->assertSame('in_progress', $case->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.document_requested', 'subject_id' => $documentRequest->id]);

        $this->actingAs($employee)->get('/my-onboarding-documents')->assertOk()->assertSee('National identity card')->assertDontSee('People Officer');
        $this->actingAs($otherEmployee)->get('/my-onboarding-documents')->assertOk()->assertDontSee('National identity card');
        $this->post('/my-onboarding-documents/'.$documentRequest->id, [
            'document' => UploadedFile::fake()->create('identity.pdf', 180, 'application/pdf'),
        ])->assertNotFound();

        $this->actingAs($employee)->from('/my-onboarding-documents')->post('/my-onboarding-documents/'.$documentRequest->id, [
            'document' => UploadedFile::fake()->create('identity.txt', 12, 'text/plain'),
        ])->assertSessionHasErrors('document');
        $this->post('/my-onboarding-documents/'.$documentRequest->id, [
            'document' => UploadedFile::fake()->create('identity.pdf', 180, 'application/pdf'),
        ])->assertRedirect();

        $documentRequest->refresh();
        $this->assertSame('submitted', $documentRequest->status);
        $this->assertSame('completed', $case->fresh()->status);
        $document = $documentRequest->document;
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame('National identity card', $document->category);
        $this->assertTrue($document->employee_visible);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.document_submitted', 'subject_id' => $documentRequest->id]);
        $this->get('/my-documents/'.$document->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($hr)->get('/onboarding/document-requests/'.$documentRequest->id.'/download')->assertOk();
    }

    public function test_hr_issues_a_private_contract_and_employee_returns_a_signed_copy(): void
    {
        Storage::fake('local');
        $hr = $this->user('onboarding.contract-hr@example.test', 'system_admin');
        $employee = $this->user('onboarding.contract-employee@example.test', 'employee');
        $otherEmployee = $this->user('onboarding.contract-other@example.test', 'employee');
        $application = $this->application('hired');
        $this->actingAs($hr)->post('/recruitment/applications/'.$application->id.'/onboarding', [
            'employee_id' => $employee->id,
            'assigned_to' => $hr->id,
            'start_on' => now()->toDateString(),
        ])->assertRedirect();
        $case = OnboardingCase::firstOrFail();

        $this->post('/onboarding/'.$case->id.'/contract', [
            'contract' => UploadedFile::fake()->create('employment-contract.pdf', 220, 'application/pdf'),
        ])->assertRedirect();
        $contract = OnboardingContract::firstOrFail();
        $this->assertSame('sent', $contract->status);
        Storage::disk('local')->assertExists($contract->original_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.contract_issued', 'subject_id' => $contract->id]);
        $this->assertSame('in_progress', $case->fresh()->status);

        $this->actingAs($employee)->get('/onboarding/contracts/'.$contract->id.'/document')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($otherEmployee)->get('/onboarding/contracts/'.$contract->id.'/document')->assertNotFound();
        $this->actingAs($employee);
        $this->post('/onboarding/contracts/'.$contract->id.'/response', [
            'response' => 'signed',
            'signed_contract' => UploadedFile::fake()->create('signed-contract.txt', 20, 'text/plain'),
        ])->assertSessionHasErrors('signed_contract');
        $this->post('/onboarding/contracts/'.$contract->id.'/response', [
            'response' => 'signed',
            'signed_contract' => UploadedFile::fake()->create('signed-contract.pdf', 230, 'application/pdf'),
        ])->assertRedirect();

        $contract->refresh();
        $this->assertSame('signed', $contract->status);
        $this->assertNotNull($contract->signed_at);
        Storage::disk('local')->assertExists($contract->signedDocument->storage_path);
        $this->assertSame('completed', $case->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.contract_signed', 'subject_id' => $contract->id]);
        $this->actingAs($hr)->get('/onboarding/contracts/'.$contract->id.'/signed-document')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/onboarding/contracts/'.$contract->id.'/response', [
            'response' => 'declined',
            'response_note' => 'duplicate response',
        ])->assertNotFound();
    }

    public function test_declined_contract_is_audited_and_hr_can_issue_a_versioned_replacement(): void
    {
        Storage::fake('local');
        $hr = $this->user('onboarding.reissue-hr@example.test', 'system_admin');
        $employee = $this->user('onboarding.reissue-employee@example.test', 'employee');
        $application = $this->application('hired');
        $this->actingAs($hr)->post('/recruitment/applications/'.$application->id.'/onboarding', [
            'employee_id' => $employee->id,
            'assigned_to' => $hr->id,
            'start_on' => now()->toDateString(),
        ])->assertRedirect();
        $case = OnboardingCase::firstOrFail();

        $this->post('/onboarding/'.$case->id.'/contract', [
            'contract' => UploadedFile::fake()->create('contract-v1.pdf', 200, 'application/pdf'),
        ])->assertRedirect();
        $firstContract = OnboardingContract::firstOrFail();
        $this->actingAs($employee)->post('/onboarding/contracts/'.$firstContract->id.'/response', ['response' => 'declined'])
            ->assertSessionHasErrors('response_note');
        $this->post('/onboarding/contracts/'.$firstContract->id.'/response', [
            'response' => 'declined',
            'response_note' => 'Please correct the job title.',
        ])->assertRedirect();
        $this->assertSame('declined', $firstContract->fresh()->status);
        $this->assertSame('in_progress', $case->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.contract_declined', 'subject_id' => $firstContract->id]);

        $this->actingAs($hr)->post('/onboarding/'.$case->id.'/contract', [
            'contract' => UploadedFile::fake()->create('contract-v2.pdf', 210, 'application/pdf'),
        ])->assertRedirect();
        $latestContract = $case->fresh()->contract;
        $this->assertSame(2, $latestContract->version);
        $this->assertSame('sent', $latestContract->status);
        $this->assertSame(2, $case->contracts()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.contract_issued', 'subject_id' => $latestContract->id]);
    }

    private function application(string $stage): JobApplication
    {
        $vacancy = JobVacancy::create([
            'title' => 'People Officer',
            'slug' => 'people-officer-'.strtolower(str()->random(6)),
            'summary' => 'People operations.',
            'description' => 'Support the team.',
            'status' => 'closed',
        ]);

        return JobApplication::create([
            'job_vacancy_id' => $vacancy->id,
            'applicant_name' => 'New Starter',
            'email' => strtolower(str()->random(10)).'@example.test',
            'resume_path' => 'recruitment/resumes/'.str()->random(16).'.pdf',
            'resume_filename' => 'resume.pdf',
            'resume_mime_type' => 'application/pdf',
            'resume_size' => 120,
            'resume_sha256' => str_repeat('a', 64),
            'stage' => $stage,
        ]);
    }

    private function user(string $email, string $role): User
    {
        $user = new User;
        $user->forceFill([
            'name' => str($email)->before('@')->replace('.', ' ')->title()->toString(),
            'email' => $email,
            'employee_no' => $role === 'employee' ? 'EMP-'.str()->upper(str()->random(6)) : null,
            'password' => Hash::make('Secure-password-123!'),
            'role' => $role,
            'is_active' => true,
        ])->save();

        return $user;
    }
}
