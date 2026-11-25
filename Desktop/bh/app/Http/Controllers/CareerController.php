<?php

namespace App\Http\Controllers;

use App\Models\JobApplication;
use App\Models\JobApplicationStageChange;
use App\Models\JobVacancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CareerController extends Controller
{
    public function index(): View
    {
        return view('careers.index', [
            'vacancies' => JobVacancy::where('status', 'open')
                ->where(fn ($query) => $query->whereNull('closes_on')->orWhereDate('closes_on', '>=', now()->toDateString()))
                ->with('branch')
                ->orderBy('closes_on')
                ->paginate(15),
        ]);
    }

    public function show(JobVacancy $jobVacancy): View
    {
        abort_unless($this->isOpen($jobVacancy), 404);

        return view('careers.show', ['vacancy' => $jobVacancy->load('branch')]);
    }

    public function apply(Request $request, JobVacancy $jobVacancy): RedirectResponse
    {
        $data = $request->validate([
            'applicant_name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'resume' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'privacy_acknowledged' => ['accepted'],
        ]);
        $uploadedFile = $data['resume'];
        $storagePath = $uploadedFile->storeAs('recruitment/resumes/'.$jobVacancy->id, Str::uuid().'.pdf', config('filesystems.private_disk'));
        if ($storagePath === false) {
            abort(500, 'The resume could not be stored.');
        }

        try {
            DB::transaction(function () use ($request, $jobVacancy, $data, $uploadedFile, $storagePath): void {
                $vacancy = JobVacancy::whereKey($jobVacancy->id)->lockForUpdate()->firstOrFail();
                if (! $this->isOpen($vacancy)) {
                    throw ValidationException::withMessages(['vacancy' => 'This vacancy is no longer accepting applications.']);
                }
                $email = mb_strtolower(trim($data['email']));
                if ($vacancy->applications()->where('email', $email)->exists()) {
                    throw ValidationException::withMessages(['email' => 'An application from this email address has already been received for this vacancy.']);
                }

                $application = $vacancy->applications()->create([
                    'applicant_name' => trim($data['applicant_name']),
                    'email' => $email,
                    'phone' => $data['phone'] ?? null,
                    'cover_letter' => $data['cover_letter'] ?? null,
                    'resume_path' => $storagePath,
                    'resume_filename' => $this->safeOriginalFilename($uploadedFile->getClientOriginalName()),
                    'resume_mime_type' => $uploadedFile->getMimeType() ?? 'application/pdf',
                    'resume_size' => $uploadedFile->getSize(),
                    'resume_sha256' => hash_file('sha256', $uploadedFile->getRealPath()),
                    'stage' => 'submitted',
                ]);
                JobApplicationStageChange::create([
                    'job_application_id' => $application->id,
                    'from_stage' => null,
                    'to_stage' => 'submitted',
                    'note' => 'Application received',
                ]);
                $this->audit($request, 'recruitment.application_received', $application, ['job_vacancy_id' => $vacancy->id]);
            });
        } catch (Throwable $exception) {
            Storage::disk(config('filesystems.private_disk'))->delete($storagePath);
            throw $exception;
        }

        return redirect()->route('careers.submitted');
    }

    public function submitted(): View
    {
        return view('careers.submitted');
    }

    private function isOpen(JobVacancy $vacancy): bool
    {
        return $vacancy->status === 'open' && ($vacancy->closes_on === null || $vacancy->closes_on->greaterThanOrEqualTo(CarbonImmutable::today()));
    }

    private function safeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? 'resume.pdf';

        return mb_substr($filename, 0, 255);
    }

    /** @param array<string, mixed> $details */
    private function audit(Request $request, string $action, JobApplication $application, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => $action,
            'subject_type' => 'job_application',
            'subject_id' => $application->id,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
