<?php

namespace App\Http\Controllers;

use App\Models\JobApplication;
use App\Models\OnboardingCase;
use App\Models\OnboardingContract;
use App\Models\OnboardingDocumentRequest;
use App\Models\OnboardingTask;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OnboardingController extends Controller
{
    public function index(Request $request): View
    {
        $cases = OnboardingCase::with(['application.vacancy', 'employee', 'owner', 'contract'])->withCount('tasks')->latest();
        if ($request->user()->role === 'hr_manager') {
            $cases->where('assigned_to', $request->user()->id);
        }

        return view('onboarding.index', ['cases' => $cases->paginate(20)]);
    }

    public function myTasks(Request $request): View
    {
        return view('onboarding.mine', ['tasks' => OnboardingTask::with('onboardingCase')->where('assigned_to', $request->user()->id)->latest()->paginate(20)]);
    }

    public function myDocuments(Request $request): View
    {
        abort_unless($request->user()->employee_no, 403);

        return view('onboarding.documents-mine', [
            'requests' => OnboardingDocumentRequest::with('document')
                ->whereHas('onboardingCase', fn ($query) => $query->where('employee_id', $request->user()->id))
                ->latest()->paginate(20),
            'contracts' => OnboardingCase::with('contract.signedDocument')
                ->where('employee_id', $request->user()->id)
                ->whereHas('contract')->latest()->get(),
        ]);
    }

    public function create(Request $request, JobApplication $jobApplication): View
    {
        abort_unless($jobApplication->stage === 'hired', 404);
        abort_if($jobApplication->onboardingCase()->exists(), 404);

        return view('onboarding.create', [
            'application' => $jobApplication->load('vacancy'),
            'employees' => User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->orderBy('name')->get(),
            'owners' => User::whereIn('role', ['system_admin', 'hr_director', 'hr_manager'])->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, JobApplication $jobApplication): RedirectResponse
    {
        abort_unless($jobApplication->stage === 'hired', 404);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')],
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->whereIn('role', ['system_admin', 'hr_director', 'hr_manager'])->where('is_active', true)],
            'start_on' => ['required', 'date'],
        ]);

        $case = DB::transaction(function () use ($request, $jobApplication, $data): OnboardingCase {
            $application = JobApplication::whereKey($jobApplication->id)->lockForUpdate()->firstOrFail();
            if ($application->stage !== 'hired' || $application->onboardingCase()->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'This hired application already has an onboarding case or is no longer eligible.']);
            }
            $case = OnboardingCase::create([...$data, 'job_application_id' => $application->id, 'created_by' => $request->user()->id, 'status' => 'in_progress']);
            $this->audit($request, 'onboarding.case_created', $case, ['employee_id' => $case->employee_id, 'assigned_to' => $case->assigned_to]);

            return $case;
        });

        return redirect()->route('onboarding.show', $case)->with('status', 'Onboarding case created. Add tasks and assign each responsibility.');
    }

    public function show(Request $request, OnboardingCase $onboardingCase): View
    {
        $this->authorizeCase($request, $onboardingCase);

        return view('onboarding.show', [
            'case' => $onboardingCase->load(['application.vacancy', 'employee', 'owner', 'tasks.assignee', 'documentRequests.document', 'contract.signedDocument', 'contract.issuer', 'contracts.issuer']),
            'assignees' => User::where('is_active', true)->whereIn('role', ['system_admin', 'hr_director', 'hr_manager', 'employee'])->orderBy('name')->get(),
        ]);
    }

    public function storeDocumentRequest(Request $request, OnboardingCase $onboardingCase): RedirectResponse
    {
        $this->authorizeCase($request, $onboardingCase);
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director']) || $onboardingCase->assigned_to === $request->user()->id, 403);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date'],
        ]);
        $documentRequest = DB::transaction(function () use ($request, $onboardingCase, $data): OnboardingDocumentRequest {
            $lockedCase = OnboardingCase::whereKey($onboardingCase->id)->lockForUpdate()->firstOrFail();
            $documentRequest = $lockedCase->documentRequests()->create([...$data, 'requested_by' => $request->user()->id]);
            $lockedCase->update(['status' => 'in_progress', 'completed_at' => null]);

            return $documentRequest;
        });
        $this->audit($request, 'onboarding.document_requested', $documentRequest, ['case_id' => $onboardingCase->id, 'category' => $documentRequest->category]);

        return back()->with('status', 'Onboarding document requested.');
    }

    public function issueContract(Request $request, OnboardingCase $onboardingCase): RedirectResponse
    {
        $this->authorizeCase($request, $onboardingCase);
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director']) || $onboardingCase->assigned_to === $request->user()->id, 403);
        $data = $request->validate(['contract' => ['required', 'file', 'mimes:pdf', 'max:10240']]);
        $uploadedFile = $data['contract'];
        $storagePath = $uploadedFile->storeAs('onboarding/contracts/'.$onboardingCase->id, Str::uuid().'.pdf', config('filesystems.private_disk'));
        if ($storagePath === false) {
            abort(500, 'The contract could not be stored.');
        }

        try {
            $contract = DB::transaction(function () use ($request, $onboardingCase, $uploadedFile, $storagePath): OnboardingContract {
                $lockedCase = OnboardingCase::whereKey($onboardingCase->id)->lockForUpdate()->firstOrFail();
                $previousContract = $lockedCase->contract;
                if ($previousContract && $previousContract->status !== 'declined') {
                    abort(409, 'A new contract can only be issued after the previous contract was declined.');
                }
                $contract = $lockedCase->contracts()->create([
                    'version' => ((int) $lockedCase->contracts()->max('version')) + 1,
                    'issued_by' => $request->user()->id,
                    'original_path' => $storagePath,
                    'original_filename' => $this->safeOriginalFilename($uploadedFile->getClientOriginalName()),
                    'original_sha256' => hash_file('sha256', $uploadedFile->getRealPath()),
                    'original_size' => $uploadedFile->getSize(),
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
                $lockedCase->update(['status' => 'in_progress', 'completed_at' => null]);
                $this->audit($request, 'onboarding.contract_issued', $contract, ['case_id' => $lockedCase->id, 'employee_id' => $lockedCase->employee_id]);

                return $contract;
            });
        } catch (Throwable $exception) {
            Storage::disk(config('filesystems.private_disk'))->delete($storagePath);
            throw $exception;
        }

        return back()->with('status', 'Contract issued to the employee’s onboarding portal.');
    }

    public function downloadContract(Request $request, OnboardingContract $onboardingContract): StreamedResponse
    {
        $case = $onboardingContract->onboardingCase;
        if ($request->user()->role === 'employee') {
            abort_unless($case->employee_id === $request->user()->id && $request->user()->is_active, 404);
            abort_unless($onboardingContract->status === 'sent', 404);
        } else {
            $this->authorizeCase($request, $case);
        }

        $disk = Storage::disk(config('filesystems.private_disk'));
        abort_unless($disk->exists($onboardingContract->original_path), 404);
        $this->audit($request, 'onboarding.contract_downloaded', $onboardingContract, ['case_id' => $case->id]);

        return $disk->download($onboardingContract->original_path, $onboardingContract->original_filename, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function respondToContract(Request $request, OnboardingContract $onboardingContract): RedirectResponse
    {
        $case = $onboardingContract->onboardingCase;
        abort_unless($request->user()->role === 'employee' && $case->employee_id === $request->user()->id && $request->user()->is_active, 404);
        abort_unless($onboardingContract->status === 'sent', 404);
        $data = $request->validate([
            'response' => ['required', Rule::in(['signed', 'declined'])],
            'signed_contract' => ['required_if:response,signed', 'file', 'mimes:pdf', 'max:10240'],
            'response_note' => ['required_if:response,declined', 'nullable', 'string', 'max:2000'],
        ]);
        $uploadedFile = $data['signed_contract'] ?? null;
        $storagePath = null;
        if ($uploadedFile) {
            $storagePath = $uploadedFile->storeAs('employee-documents/'.$case->employee_id, Str::uuid().'.pdf', config('filesystems.private_disk'));
            if ($storagePath === false) {
                abort(500, 'The signed contract could not be stored.');
            }
        }

        try {
            DB::transaction(function () use ($request, $onboardingContract, $case, $data, $uploadedFile, $storagePath): void {
                $lockedCase = OnboardingCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                $contract = OnboardingContract::whereKey($onboardingContract->id)->lockForUpdate()->firstOrFail();
                if ($contract->status !== 'sent') {
                    abort(409, 'This contract has already received a response.');
                }
                if ($data['response'] === 'declined') {
                    $contract->update(['status' => 'declined', 'declined_at' => now(), 'response_note' => $data['response_note']]);
                    $this->audit($request, 'onboarding.contract_declined', $contract, ['case_id' => $lockedCase->id]);

                    return;
                }

                $document = $lockedCase->employee->documents()->create([
                    'uploaded_by' => $request->user()->id,
                    'category' => 'Signed employment contract',
                    'original_filename' => $this->safeOriginalFilename($uploadedFile->getClientOriginalName()),
                    'storage_path' => $storagePath,
                    'mime_type' => $uploadedFile->getMimeType() ?? 'application/pdf',
                    'file_size' => $uploadedFile->getSize(),
                    'sha256' => hash_file('sha256', $uploadedFile->getRealPath()),
                    'employee_visible' => true,
                ]);
                $contract->update([
                    'status' => 'signed',
                    'signed_document_id' => $document->id,
                    'signed_at' => now(),
                ]);
                $this->refreshCaseStatus($lockedCase);
                $this->audit($request, 'onboarding.contract_signed', $contract, ['case_id' => $lockedCase->id, 'employee_document_id' => $document->id]);
            });
        } catch (Throwable $exception) {
            if ($storagePath !== null) {
                Storage::disk(config('filesystems.private_disk'))->delete($storagePath);
            }
            throw $exception;
        }

        return back()->with('status', $data['response'] === 'signed' ? 'Signed contract uploaded securely.' : 'Contract declined. HR has been notified in the onboarding record.');
    }

    public function downloadSignedContract(Request $request, OnboardingContract $onboardingContract): StreamedResponse
    {
        $case = $onboardingContract->onboardingCase;
        $this->authorizeCase($request, $case);
        abort_unless($onboardingContract->status === 'signed' && $onboardingContract->signedDocument, 404);
        $document = $onboardingContract->signedDocument;
        $disk = Storage::disk(config('filesystems.private_disk'));
        abort_unless($disk->exists($document->storage_path), 404);
        $this->audit($request, 'onboarding.signed_contract_downloaded', $onboardingContract, ['case_id' => $case->id, 'employee_document_id' => $document->id]);

        return $disk->download($document->storage_path, $document->original_filename, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function submitDocument(Request $request, OnboardingDocumentRequest $onboardingDocumentRequest): RedirectResponse
    {
        $case = $onboardingDocumentRequest->onboardingCase;
        abort_unless($case->employee_id === $request->user()->id, 404);
        abort_unless($request->user()->role === 'employee' && $request->user()->is_active, 403);
        abort_unless($onboardingDocumentRequest->status === 'requested' && $onboardingDocumentRequest->employee_document_id === null, 404);
        $data = $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $uploadedFile = $data['document'];
        $extension = $uploadedFile->guessExtension() ?? $uploadedFile->extension();
        $storagePath = $uploadedFile->storeAs('employee-documents/'.$case->employee_id, Str::uuid().'.'.$extension, config('filesystems.private_disk'));
        if ($storagePath === false) {
            abort(500, 'The document could not be stored.');
        }

        try {
            DB::transaction(function () use ($request, $onboardingDocumentRequest, $case, $uploadedFile, $storagePath): void {
                $lockedCase = OnboardingCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                $lockedRequest = OnboardingDocumentRequest::whereKey($onboardingDocumentRequest->id)->lockForUpdate()->firstOrFail();
                if ($lockedRequest->status !== 'requested' || $lockedRequest->employee_document_id !== null) {
                    abort(409, 'This document request has already been fulfilled.');
                }
                $document = $case->employee->documents()->create([
                    'uploaded_by' => $request->user()->id,
                    'category' => $lockedRequest->category,
                    'original_filename' => $this->safeOriginalFilename($uploadedFile->getClientOriginalName()),
                    'storage_path' => $storagePath,
                    'mime_type' => $uploadedFile->getMimeType() ?? 'application/octet-stream',
                    'file_size' => $uploadedFile->getSize(),
                    'sha256' => hash_file('sha256', $uploadedFile->getRealPath()),
                    'employee_visible' => true,
                ]);
                $lockedRequest->update(['employee_document_id' => $document->id, 'status' => 'submitted', 'submitted_at' => now()]);
                $this->refreshCaseStatus($lockedCase);
                $this->audit($request, 'onboarding.document_submitted', $lockedRequest, ['case_id' => $case->id, 'employee_document_id' => $document->id]);
            });
        } catch (Throwable $exception) {
            Storage::disk(config('filesystems.private_disk'))->delete($storagePath);
            throw $exception;
        }

        return back()->with('status', 'Document uploaded securely. HR can review it in the onboarding case.');
    }

    public function downloadOnboardingDocument(Request $request, OnboardingDocumentRequest $onboardingDocumentRequest): StreamedResponse
    {
        $case = $onboardingDocumentRequest->onboardingCase;
        $this->authorizeCase($request, $case);
        abort_unless($onboardingDocumentRequest->status === 'submitted' && $onboardingDocumentRequest->document, 404);
        $document = $onboardingDocumentRequest->document;
        $disk = Storage::disk(config('filesystems.private_disk'));
        abort_unless($disk->exists($document->storage_path), 404);
        $this->audit($request, 'employee_document.downloaded', $document, ['employee_id' => $document->employee_id, 'onboarding_case_id' => $case->id]);

        return $disk->download($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function storeTask(Request $request, OnboardingCase $onboardingCase): RedirectResponse
    {
        $this->authorizeCase($request, $onboardingCase);
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director']) || $onboardingCase->assigned_to === $request->user()->id, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereIn('role', ['system_admin', 'hr_director', 'hr_manager', 'employee'])],
            'due_on' => ['nullable', 'date'],
        ]);
        $task = $onboardingCase->tasks()->create([...$data, 'status' => 'pending']);
        $onboardingCase->update(['status' => 'in_progress', 'completed_at' => null]);
        $this->audit($request, 'onboarding.task_created', $task, ['case_id' => $onboardingCase->id, 'assigned_to' => $task->assigned_to]);

        return back()->with('status', 'Onboarding task added.');
    }

    public function completeTask(Request $request, OnboardingTask $onboardingTask): RedirectResponse
    {
        $case = $onboardingTask->onboardingCase;
        abort_unless($request->user()->id === $onboardingTask->assigned_to || $request->user()->id === $case->assigned_to || in_array($request->user()->role, ['system_admin', 'hr_director'], true), 403);
        $data = $request->validate(['completion_note' => ['nullable', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $onboardingTask, $case, $data): void {
            $lockedCase = OnboardingCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $task = OnboardingTask::whereKey($onboardingTask->id)->lockForUpdate()->firstOrFail();
            if ($task->status === 'completed') {
                throw ValidationException::withMessages(['completion_note' => 'This task is already complete.']);
            }
            $task->update(['status' => 'completed', 'completed_by' => $request->user()->id, 'completed_at' => now(), 'completion_note' => $data['completion_note'] ?? null]);
            $this->refreshCaseStatus($lockedCase);
            $this->audit($request, 'onboarding.task_completed', $task, ['case_id' => $case->id]);
        });

        return back()->with('status', 'Onboarding task marked complete.');
    }

    private function authorizeCase(Request $request, OnboardingCase $case): void
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['system_admin', 'hr_director'], true) || $case->assigned_to === $user->id, 403);
    }

    private function audit(Request $request, string $action, object $subject, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function safeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? 'employee-document';

        return mb_substr($filename, 0, 255);
    }

    private function refreshCaseStatus(OnboardingCase $case): void
    {
        $hasTasks = $case->tasks()->exists();
        $hasDocumentRequests = $case->documentRequests()->exists();
        $hasContract = $case->contract()->exists();
        $hasOpenTasks = $case->tasks()->where('status', '!=', 'completed')->exists();
        $hasOpenDocumentRequests = $case->documentRequests()->where('status', '!=', 'submitted')->exists();
        $hasOpenContract = $case->contract()->where('status', '!=', 'signed')->exists();
        $isComplete = ($hasTasks || $hasDocumentRequests || $hasContract)
            && ! $hasOpenTasks && ! $hasOpenDocumentRequests && ! $hasOpenContract;

        $case->update([
            'status' => $isComplete ? 'completed' : 'in_progress',
            'completed_at' => $isComplete ? now() : null,
        ]);
    }
}
