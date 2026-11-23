<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\JobApplication;
use App\Models\JobApplicationStageChange;
use App\Models\JobVacancy;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOffer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecruitmentController extends Controller
{
    private const STAGES = ['screening', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'withdrawn', 'declined'];

    public function index(): View
    {
        return view('recruitment.index', [
            'vacancies' => JobVacancy::with('branch')->withCount('applications')->latest()->paginate(20),
            'applicationCounts' => JobApplication::select('stage', DB::raw('count(*) as aggregate'))->groupBy('stage')->pluck('aggregate', 'stage'),
            'vacancyCount' => JobVacancy::count(),
            'openVacancyCount' => JobVacancy::where('status', 'open')->where(fn (Builder $query) => $query->whereNull('closes_on')->orWhereDate('closes_on', '>=', now()->toDateString()))->count(),
            'applicationCount' => JobApplication::count(),
        ]);
    }

    public function createVacancy(): View
    {
        return view('recruitment.vacancy-form', [
            'vacancy' => new JobVacancy(['status' => 'draft']),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'editing' => false,
        ]);
    }

    public function storeVacancy(Request $request): RedirectResponse
    {
        $data = $request->validate($this->vacancyRules());
        $this->validateVacancyStatus($data);
        $vacancy = JobVacancy::create([...$data, 'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(6)), 'created_by' => $request->user()->id]);
        $this->audit($request, 'recruitment.vacancy_created', $vacancy, ['status' => $vacancy->status]);

        return redirect()->route('recruitment.vacancies.edit', $vacancy)->with('status', 'Vacancy saved.');
    }

    public function editVacancy(JobVacancy $jobVacancy): View
    {
        return view('recruitment.vacancy-form', [
            'vacancy' => $jobVacancy,
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'editing' => true,
        ]);
    }

    public function updateVacancy(Request $request, JobVacancy $jobVacancy): RedirectResponse
    {
        $data = $request->validate($this->vacancyRules());
        $this->validateVacancyStatus($data);
        $originalValues = $jobVacancy->only(['title', 'department', 'employment_type', 'branch_id', 'location', 'status', 'closes_on']);
        $jobVacancy->update($data);
        $this->audit($request, 'recruitment.vacancy_updated', $jobVacancy, [
            'original_values' => $originalValues,
            'revised_values' => $jobVacancy->only(['title', 'department', 'employment_type', 'branch_id', 'location', 'status', 'closes_on']),
        ]);

        return back()->with('status', 'Vacancy updated.');
    }

    public function applications(Request $request): View
    {
        $filters = $this->validatedApplicationFilters($request);
        $applications = $this->filteredApplications($filters)->with('vacancy')->latest()->paginate(20)->withQueryString();

        return view('recruitment.applications.index', [
            'applications' => $applications,
            'vacancies' => JobVacancy::orderBy('title')->get(['id', 'title']),
            'filters' => [...$filters, 'stage' => $filters['stage'] ?? null, 'vacancy_id' => isset($filters['vacancy_id']) ? (int) $filters['vacancy_id'] : null, 'q' => $filters['q'] ?? null],
        ]);
    }

    public function exportApplications(Request $request): StreamedResponse
    {
        $filters = $this->validatedApplicationFilters($request);
        $applications = $this->filteredApplications($filters)->with('vacancy')->orderBy('id')->lazy(1000);

        return response()->streamDownload(function () use ($applications): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Applicant', 'Email', 'Phone', 'Vacancy', 'Department', 'Stage', 'Applied on']);
            foreach ($applications as $application) {
                fputcsv($output, [
                    $this->safeCsvValue($application->applicant_name),
                    $this->safeCsvValue($application->email),
                    $this->safeCsvValue($application->phone),
                    $this->safeCsvValue($application->vacancy->title),
                    $this->safeCsvValue($application->vacancy->department),
                    $this->safeCsvValue($application->stage),
                    $application->created_at->toDateString(),
                ]);
            }
            fclose($output);
        }, 'recruitment-applications-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function showApplication(JobApplication $jobApplication): View
    {
        return view('recruitment.applications.show', [
            'application' => $jobApplication->load(['vacancy.branch', 'reviewer', 'stageChanges.actor', 'interviews.interviewer', 'offers.issuer']),
            'interviewers' => User::whereIn('role', ['system_admin', 'hr_director'])->where('is_active', true)->orderBy('name')->get(),
            'stages' => self::STAGES,
        ]);
    }

    public function updateStage(Request $request, JobApplication $jobApplication): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(self::STAGES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $jobApplication, $data): void {
            $application = JobApplication::whereKey($jobApplication->id)->lockForUpdate()->firstOrFail();
            if (in_array($application->stage, ['hired', 'rejected', 'withdrawn', 'declined'], true)) {
                throw ValidationException::withMessages(['stage' => 'This application is closed and cannot move to another stage.']);
            }
            if ($application->stage === $data['stage']) {
                throw ValidationException::withMessages(['stage' => 'Choose a different recruitment stage.']);
            }
            if (in_array($data['stage'], ['rejected', 'hired'], true) && trim($data['note'] ?? '') === '') {
                throw ValidationException::withMessages(['note' => 'A decision note is required for hiring or rejection.']);
            }

            $previousStage = $application->stage;
            $application->update(['stage' => $data['stage'], 'reviewed_by' => $request->user()->id]);
            if ($previousStage === 'offer') {
                RecruitmentOffer::where('job_application_id', $application->id)
                    ->where('status', 'issued')
                    ->update(['status' => 'revoked']);
            }
            JobApplicationStageChange::create([
                'job_application_id' => $application->id,
                'changed_by' => $request->user()->id,
                'from_stage' => $previousStage,
                'to_stage' => $data['stage'],
                'note' => $data['note'] ?? null,
            ]);
            $this->audit($request, 'recruitment.application_stage_changed', $application, ['from_stage' => $previousStage, 'to_stage' => $data['stage']]);
        });

        return back()->with('status', 'Application moved to '.str_replace('_', ' ', $data['stage']).'.');
    }

    public function scheduleInterview(Request $request, JobApplication $jobApplication): RedirectResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'interviewer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', ['system_admin', 'hr_director'])->where('is_active', true)],
            'location' => ['nullable', 'string', 'max:180', 'required_without:meeting_url'],
            'meeting_url' => ['nullable', 'url', 'starts_with:https://', 'max:500', 'required_without:location'],
        ]);

        DB::transaction(function () use ($request, $jobApplication, $data): void {
            $application = JobApplication::whereKey($jobApplication->id)->lockForUpdate()->firstOrFail();
            if (! in_array($application->stage, ['shortlisted', 'interview'], true)) {
                throw ValidationException::withMessages(['starts_at' => 'Shortlist the applicant before scheduling an interview.']);
            }
            $interview = $application->interviews()->create([...$data, 'created_by' => $request->user()->id]);
            if ($application->stage === 'shortlisted') {
                $application->update(['stage' => 'interview', 'reviewed_by' => $request->user()->id]);
                JobApplicationStageChange::create([
                    'job_application_id' => $application->id,
                    'changed_by' => $request->user()->id,
                    'from_stage' => 'shortlisted',
                    'to_stage' => 'interview',
                    'note' => 'Interview scheduled #'.$interview->id,
                ]);
            }
            $this->audit($request, 'recruitment.interview_scheduled', $application, ['interview_id' => $interview->id, 'starts_at' => $interview->starts_at->toIso8601String()]);
        });

        return back()->with('status', 'Interview scheduled.');
    }

    public function recordInterviewFeedback(Request $request, JobApplication $jobApplication, RecruitmentInterview $interview): RedirectResponse
    {
        abort_unless($interview->job_application_id === $jobApplication->id, 404);
        $data = $request->validate([
            'feedback' => ['required', 'string', 'max:5000'],
            'outcome' => ['required', Rule::in(['pass', 'hold', 'fail', 'no_show'])],
        ]);
        $interview->update(['feedback' => $data['feedback'], 'outcome' => $data['outcome'], 'status' => $data['outcome'] === 'no_show' ? 'no_show' : 'completed']);
        $this->audit($request, 'recruitment.interview_feedback_recorded', $jobApplication, ['interview_id' => $interview->id, 'outcome' => $interview->outcome]);

        return back()->with('status', 'Interview feedback saved.');
    }

    public function downloadResume(Request $request, JobApplication $jobApplication): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($jobApplication->resume_path), 404);
        $this->audit($request, 'recruitment.resume_downloaded', $jobApplication, []);

        return $disk->download($jobApplication->resume_path, $jobApplication->resume_filename, [
            'Content-Type' => $jobApplication->resume_mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadOfferForHr(Request $request, JobApplication $jobApplication, RecruitmentOffer $recruitmentOffer): StreamedResponse
    {
        abort_unless($recruitmentOffer->job_application_id === $jobApplication->id, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($recruitmentOffer->letter_path), 404);
        $this->audit($request, 'recruitment.offer_downloaded_by_hr', $jobApplication, ['offer_id' => $recruitmentOffer->id]);

        return $disk->download($recruitmentOffer->letter_path, $recruitmentOffer->letter_filename, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function issueOffer(Request $request, JobApplication $jobApplication): RedirectResponse
    {
        $data = $request->validate([
            'offer_letter' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'expires_in_days' => ['required', 'integer', 'between:1,30'],
        ]);
        abort_unless($jobApplication->stage === 'offer', 409);
        $uploadedFile = $data['offer_letter'];
        $storagePath = $uploadedFile->storeAs('recruitment/offers/'.$jobApplication->id, Str::uuid().'.pdf', 'local');
        if ($storagePath === false) {
            abort(500, 'The offer letter could not be stored.');
        }
        $token = Str::random(64);
        $expiresAt = CarbonImmutable::now()->addDays((int) $data['expires_in_days']);

        try {
            $offer = DB::transaction(function () use ($request, $jobApplication, $uploadedFile, $storagePath, $token, $expiresAt): RecruitmentOffer {
                $application = JobApplication::whereKey($jobApplication->id)->lockForUpdate()->firstOrFail();
                if ($application->stage !== 'offer') {
                    throw ValidationException::withMessages(['offer_letter' => 'Move the applicant to the offer stage before issuing a letter.']);
                }
                RecruitmentOffer::where('job_application_id', $application->id)
                    ->where('status', 'issued')
                    ->where('expires_at', '<=', now())
                    ->update(['status' => 'expired']);
                if (RecruitmentOffer::where('job_application_id', $application->id)->where('status', 'issued')->exists()) {
                    throw ValidationException::withMessages(['offer_letter' => 'An active offer already exists for this applicant.']);
                }

                $offer = RecruitmentOffer::create([
                    'job_application_id' => $application->id,
                    'issued_by' => $request->user()->id,
                    'letter_path' => $storagePath,
                    'letter_filename' => $this->safeOriginalFilename($uploadedFile->getClientOriginalName()),
                    'letter_sha256' => hash_file('sha256', $uploadedFile->getRealPath()),
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => $expiresAt,
                    'status' => 'issued',
                ]);
                $this->audit($request, 'recruitment.offer_issued', $application, ['offer_id' => $offer->id, 'expires_at' => $expiresAt->toIso8601String()]);

                return $offer;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storagePath);
            throw $exception;
        }

        $offerUrl = URL::temporarySignedRoute('careers.offer', $offer->expires_at, ['recruitmentOffer' => $offer->id, 'token' => $token]);

        return back()->with('status', 'Offer letter issued. Copy the secure candidate link and send it through your approved channel.')->with('issuedOfferLink', $offerUrl);
    }

    public function viewOffer(Request $request, RecruitmentOffer $recruitmentOffer): View
    {
        $this->authorizeOfferLink($request, $recruitmentOffer);

        return view('careers.offer', [
            'offer' => $recruitmentOffer->load('application.vacancy'),
            'letterUrl' => URL::temporarySignedRoute('careers.offer.document', $recruitmentOffer->expires_at, [
                'recruitmentOffer' => $recruitmentOffer->id,
                'token' => $request->query('token'),
            ]),
            'responseUrl' => URL::temporarySignedRoute('careers.offer.respond', $recruitmentOffer->expires_at, [
                'recruitmentOffer' => $recruitmentOffer->id,
                'token' => $request->query('token'),
            ]),
        ]);
    }

    public function downloadOfferLetter(Request $request, RecruitmentOffer $recruitmentOffer): StreamedResponse
    {
        $this->authorizeOfferLink($request, $recruitmentOffer);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($recruitmentOffer->letter_path), 404);
        $this->audit($request, 'recruitment.offer_downloaded', $recruitmentOffer->application, ['offer_id' => $recruitmentOffer->id]);

        return $disk->download($recruitmentOffer->letter_path, $recruitmentOffer->letter_filename, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function respondToOffer(Request $request, RecruitmentOffer $recruitmentOffer): RedirectResponse
    {
        $this->authorizeOfferLink($request, $recruitmentOffer);
        $data = $request->validate([
            'response' => ['required', Rule::in(['accepted', 'declined'])],
            'response_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $recruitmentOffer, $data): void {
            $offer = RecruitmentOffer::whereKey($recruitmentOffer->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOfferLink($request, $offer);
            $application = JobApplication::whereKey($offer->job_application_id)->lockForUpdate()->firstOrFail();
            if ($application->stage !== 'offer') {
                throw ValidationException::withMessages(['response' => 'This offer is no longer awaiting a response.']);
            }

            $offer->update([
                'status' => $data['response'],
                'responded_at' => now(),
                'response_note' => $data['response_note'] ?? null,
            ]);
            $newStage = $data['response'] === 'accepted' ? 'hired' : 'declined';
            $application->update(['stage' => $newStage]);
            JobApplicationStageChange::create([
                'job_application_id' => $application->id,
                'from_stage' => 'offer',
                'to_stage' => $newStage,
                'note' => $data['response_note'] ?? 'Offer '.$data['response'].' by applicant',
            ]);
            $this->audit($request, 'recruitment.offer_'.$data['response'], $application, ['offer_id' => $offer->id]);
        });

        return redirect()->route('careers.offer.response')->with('response', $data['response']);
    }

    public function offerResponse(): View
    {
        return view('careers.offer-response');
    }

    /** @return array<string, array<int, string>> */
    private function vacancyRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'department' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['nullable', 'in:permanent,fixed_term,casual,part_time,intern,consultant,other'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'location' => ['nullable', 'string', 'max:180'],
            'summary' => ['required', 'string', 'max:1000'],
            'description' => ['required', 'string', 'max:30000'],
            'requirements' => ['nullable', 'string', 'max:30000'],
            'status' => ['required', 'in:draft,open,closed'],
            'closes_on' => ['nullable', 'date'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function validateVacancyStatus(array $data): void
    {
        if (($data['status'] ?? null) === 'open' && ! empty($data['closes_on']) && CarbonImmutable::parse($data['closes_on'])->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['closes_on' => 'An open vacancy cannot have a closing date in the past.']);
        }
    }

    /** @return array{stage: string|null, vacancy_id: int|null, q: string|null} */
    private function validatedApplicationFilters(Request $request): array
    {
        $filters = $request->validate([
            'stage' => ['nullable', Rule::in(['submitted', ...self::STAGES])],
            'vacancy_id' => ['nullable', 'integer', 'exists:job_vacancies,id'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return [
            'stage' => $filters['stage'] ?? null,
            'vacancy_id' => isset($filters['vacancy_id']) ? (int) $filters['vacancy_id'] : null,
            'q' => $filters['q'] ?? null,
        ];
    }

    /** @param array{stage: string|null, vacancy_id: int|null, q: string|null} $filters */
    private function filteredApplications(array $filters): Builder
    {
        return JobApplication::query()
            ->when($filters['stage'] !== null, fn (Builder $query) => $query->where('stage', $filters['stage']))
            ->when($filters['vacancy_id'] !== null, fn (Builder $query) => $query->where('job_vacancy_id', $filters['vacancy_id']))
            ->when($filters['q'] !== null, function (Builder $query) use ($filters): void {
                $query->where(function (Builder $search) use ($filters): void {
                    $term = '%'.$filters['q'].'%';
                    $search->where('applicant_name', 'like', $term)->orWhere('email', 'like', $term);
                });
            });
    }

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+@\-]/', $value) === 1 ? "'".$value : $value;
    }

    private function audit(Request $request, string $action, JobApplication|JobVacancy $subject, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject instanceof JobVacancy ? 'job_vacancy' : 'job_application',
            'subject_id' => $subject->id,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authorizeOfferLink(Request $request, RecruitmentOffer $offer): void
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && hash_equals($offer->token_hash, hash('sha256', $token)), 404);
        abort_unless($offer->status === 'issued' && $offer->expires_at->isFuture(), 404);
        abort_unless($offer->application()->where('stage', 'offer')->exists(), 404);
    }

    private function safeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? 'offer-letter.pdf';

        return mb_substr($filename, 0, 255);
    }
}
