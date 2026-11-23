<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RecruitmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_careers_only_exposes_open_vacancies_and_submissions_store_private_resumes(): void
    {
        Storage::fake('local');
        $openVacancy = $this->vacancy('Operations Analyst', 'open');
        $closedVacancy = $this->vacancy('Finance Officer', 'closed');

        $this->get('/careers')->assertOk()->assertSee('Operations Analyst')->assertDontSee('Finance Officer');
        $this->get('/careers/'.$closedVacancy->slug)->assertNotFound();
        $this->get('/careers/'.$openVacancy->slug)->assertOk()->assertSee('Apply for this role');

        $this->post('/careers/'.$openVacancy->slug.'/applications', [
            'applicant_name' => 'Asha Candidate',
            'email' => 'ASHA@example.test',
            'phone' => '+255 700 123456',
            'cover_letter' => 'I have relevant experience.',
            'resume' => UploadedFile::fake()->create('asha-resume.pdf', 120, 'application/pdf'),
            'privacy_acknowledged' => '1',
        ])->assertRedirect('/careers/submitted');

        $application = JobApplication::firstOrFail();
        $this->assertSame('asha@example.test', $application->email);
        $this->assertSame('submitted', $application->stage);
        Storage::disk('local')->assertExists($application->resume_path);
        $this->assertDatabaseHas('job_application_stage_changes', ['job_application_id' => $application->id, 'to_stage' => 'submitted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recruitment.application_received', 'subject_id' => $application->id]);

        $this->from('/careers/'.$openVacancy->slug)->post('/careers/'.$openVacancy->slug.'/applications', [
            'applicant_name' => 'Asha Candidate',
            'email' => 'asha@example.test',
            'resume' => UploadedFile::fake()->create('duplicate.pdf', 100, 'application/pdf'),
            'privacy_acknowledged' => '1',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('job_applications', 1);
    }

    public function test_hr_can_manage_the_application_pipeline_schedule_interviews_and_record_feedback(): void
    {
        Storage::fake('local');
        $administrator = $this->user('recruitment.admin@example.test', 'system_admin');
        $vacancy = $this->vacancy('People Partner', 'open', $administrator);
        $this->post('/careers/'.$vacancy->slug.'/applications', [
            'applicant_name' => '=Musa Candidate',
            'email' => 'musa@example.test',
            'resume' => UploadedFile::fake()->create('musa.pdf', 100, 'application/pdf'),
            'privacy_acknowledged' => '1',
        ])->assertRedirect('/careers/submitted');
        $application = JobApplication::firstOrFail();

        $this->actingAs($administrator)->get('/recruitment')->assertOk()->assertSee('People Partner');
        $this->get('/recruitment/applications')->assertOk()->assertSee('Musa Candidate');
        $export = $this->get('/recruitment/applications/export?stage=submitted')->assertDownload('recruitment-applications-'.now()->format('Y-m-d').'.csv');
        $this->assertStringContainsString("'=Musa Candidate", $export->streamedContent());
        $this->get('/recruitment/applications/'.$application->id)->assertOk()->assertSee('Musa Candidate');
        $this->get('/recruitment/applications/'.$application->id.'/resume')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->post('/recruitment/applications/'.$application->id.'/stage', ['stage' => 'shortlisted', 'note' => 'Meets the role requirements'])->assertRedirect();
        $this->post('/recruitment/applications/'.$application->id.'/interviews', [
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i'),
            'interviewer_id' => $administrator->id,
            'location' => 'Dar es Salaam office',
        ])->assertRedirect();
        $interview = RecruitmentInterview::firstOrFail();
        $this->assertSame('interview', $application->fresh()->stage);

        $this->post('/recruitment/applications/'.$application->id.'/interviews/'.$interview->id.'/feedback', [
            'feedback' => 'Strong examples and relevant experience.',
            'outcome' => 'pass',
        ])->assertRedirect();
        $this->assertSame('completed', $interview->fresh()->status);

        $this->post('/recruitment/applications/'.$application->id.'/stage', ['stage' => 'offer', 'note' => 'Panel approved an offer'])->assertRedirect();
        $this->post('/recruitment/applications/'.$application->id.'/offer', [
            'offer_letter' => UploadedFile::fake()->create('employment-offer.pdf', 120, 'application/pdf'),
            'expires_in_days' => 14,
        ])->assertRedirect()->assertSessionHas('issuedOfferLink');
        $offer = RecruitmentOffer::firstOrFail();
        Storage::disk('local')->assertExists($offer->letter_path);
        $offerLink = session('issuedOfferLink');
        $this->get($offerLink)->assertOk()->assertSee('Accept offer');

        $query = [];
        parse_str((string) parse_url($offerLink, PHP_URL_QUERY), $query);
        $token = $query['token'];
        $letterLink = URL::temporarySignedRoute('careers.offer.document', $offer->expires_at, ['recruitmentOffer' => $offer->id, 'token' => $token]);
        $this->get($letterLink)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $responseLink = URL::temporarySignedRoute('careers.offer.respond', $offer->expires_at, ['recruitmentOffer' => $offer->id, 'token' => $token]);
        $this->post($responseLink, ['response' => 'accepted', 'response_note' => 'I accept the terms'])->assertRedirect('/careers/offer-response');
        $this->assertSame('accepted', $offer->fresh()->status);
        $this->assertSame('hired', $application->fresh()->stage);
        $this->post($responseLink, ['response' => 'declined'])->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'recruitment.interview_scheduled', 'subject_id' => $application->id]);
        $this->post('/recruitment/applications/'.$application->id.'/stage', ['stage' => 'rejected', 'note' => 'late change'])->assertSessionHasErrors('stage');
    }

    public function test_non_hr_users_cannot_view_candidate_records_and_invalid_resumes_are_rejected(): void
    {
        Storage::fake('local');
        $employee = $this->user('recruitment.employee@example.test', 'employee', 'REC-101');
        $vacancy = $this->vacancy('Project Officer', 'open');

        $this->actingAs($employee)->get('/recruitment')->assertForbidden();
        $this->actingAs($employee)->post('/careers/'.$vacancy->slug.'/applications', [
            'applicant_name' => 'Private Applicant',
            'email' => 'private@example.test',
            'resume' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
            'privacy_acknowledged' => '1',
        ])->assertSessionHasErrors('resume');
        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_hr_can_create_publish_and_close_a_vacancy(): void
    {
        $administrator = $this->user('recruitment.vacancy-admin@example.test', 'system_admin');
        $this->actingAs($administrator)->get('/recruitment/vacancies/create')->assertOk();
        $payload = [
            'title' => 'Finance Analyst',
            'department' => 'Finance',
            'employment_type' => 'permanent',
            'location' => 'Dar es Salaam',
            'summary' => 'Support financial planning and analysis.',
            'description' => 'Prepare reports and analyze business performance.',
            'requirements' => 'Relevant degree and analytical experience.',
            'status' => 'open',
            'closes_on' => now()->addMonth()->toDateString(),
        ];

        $this->post('/recruitment/vacancies', $payload)->assertRedirect();
        $vacancy = JobVacancy::where('title', 'Finance Analyst')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => 'recruitment.vacancy_created', 'subject_id' => $vacancy->id]);
        $this->get('/careers/'.$vacancy->slug)->assertOk();

        $this->put('/recruitment/vacancies/'.$vacancy->id, [...$payload, 'status' => 'closed'])->assertRedirect();
        $this->assertSame('closed', $vacancy->fresh()->status);
        $this->get('/careers/'.$vacancy->slug)->assertNotFound();
    }

    public function test_leaving_offer_stage_revokes_the_candidate_link(): void
    {
        $administrator = $this->user('recruitment.revoke-admin@example.test', 'system_admin');
        $vacancy = $this->vacancy('Data Analyst', 'closed', $administrator);
        $application = JobApplication::create([
            'job_vacancy_id' => $vacancy->id,
            'applicant_name' => 'Kito Candidate',
            'email' => 'kito@example.test',
            'resume_path' => 'recruitment/resumes/private.pdf',
            'resume_filename' => 'private.pdf',
            'resume_mime_type' => 'application/pdf',
            'resume_size' => 120,
            'resume_sha256' => str_repeat('a', 64),
            'stage' => 'offer',
        ]);
        $token = str()->random(64);
        $offer = RecruitmentOffer::create([
            'job_application_id' => $application->id,
            'issued_by' => $administrator->id,
            'letter_path' => 'recruitment/offers/private.pdf',
            'letter_filename' => 'private-offer.pdf',
            'letter_sha256' => str_repeat('b', 64),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(10),
            'status' => 'issued',
        ]);
        $offerLink = URL::temporarySignedRoute('careers.offer', $offer->expires_at, ['recruitmentOffer' => $offer->id, 'token' => $token]);
        $this->get($offerLink)->assertOk();

        $this->actingAs($administrator)->post('/recruitment/applications/'.$application->id.'/stage', [
            'stage' => 'rejected',
            'note' => 'Offer withdrawn after final review',
        ])->assertRedirect();

        $this->assertSame('revoked', $offer->fresh()->status);
        $this->get($offerLink)->assertNotFound();
    }

    private function vacancy(string $title, string $status, ?User $creator = null): JobVacancy
    {
        return JobVacancy::create([
            'created_by' => $creator?->id,
            'title' => $title,
            'slug' => str()->slug($title).'-'.str()->random(6),
            'summary' => 'A role helping the organization deliver its objectives.',
            'description' => 'Role responsibilities and expectations.',
            'requirements' => 'Relevant experience and skills.',
            'status' => $status,
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    private function user(string $email, string $role, ?string $employeeNumber = null): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Test '.ucfirst($role),
            'email' => $email,
            'password' => Hash::make('SecureStartPassword123!'),
            'role' => $role,
            'employee_no' => $employeeNumber,
            'is_active' => true,
        ])->save();

        return $user;
    }
}
