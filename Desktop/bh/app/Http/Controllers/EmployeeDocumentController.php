<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EmployeeDocumentController extends Controller
{
    public function index(Request $request, User $employee): View
    {
        $this->authorizeEmployeeManagement($request->user(), $employee);
        abort_unless($employee->employee_no, 404);

        return view('employees.documents', [
            'employee' => $employee,
            'documents' => $employee->documents()->with('uploader')->latest()->paginate(20),
        ]);
    }

    public function store(Request $request, User $employee): RedirectResponse
    {
        $this->authorizeEmployeeManagement($request->user(), $employee);
        abort_unless($employee->employee_no, 404);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'expires_on' => ['nullable', 'date'],
            'employee_visible' => ['nullable', 'boolean'],
        ]);
        $uploadedFile = $data['document'];
        $extension = $uploadedFile->guessExtension() ?? $uploadedFile->extension();
        $storagePath = $uploadedFile->storeAs('employee-documents/'.$employee->id, Str::uuid().'.'.$extension, config('filesystems.private_disk'));
        if ($storagePath === false) {
            abort(500, 'The document could not be stored.');
        }

        try {
            DB::transaction(function () use ($request, $employee, $data, $uploadedFile, $storagePath): void {
                $document = $employee->documents()->create([
                    'uploaded_by' => $request->user()->id,
                    'category' => $data['category'],
                    'original_filename' => $this->safeOriginalFilename($uploadedFile->getClientOriginalName()),
                    'storage_path' => $storagePath,
                    'mime_type' => $uploadedFile->getMimeType() ?? 'application/octet-stream',
                    'file_size' => $uploadedFile->getSize(),
                    'sha256' => hash_file('sha256', $uploadedFile->getRealPath()),
                    'expires_on' => $data['expires_on'] ?? null,
                    'employee_visible' => (bool) ($data['employee_visible'] ?? false),
                ]);
                $this->audit($request, 'employee_document.uploaded', $document, [
                    'employee_id' => $employee->id,
                    'category' => $document->category,
                    'employee_visible' => $document->employee_visible,
                    'expires_on' => $document->expires_on?->toDateString(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk(config('filesystems.private_disk'))->delete($storagePath);
            throw $exception;
        }

        return redirect()->route('employees.documents', $employee)->with('status', 'Document stored securely.');
    }

    public function archive(Request $request, User $employee, EmployeeDocument $employeeDocument): RedirectResponse
    {
        $this->authorizeEmployeeManagement($request->user(), $employee);
        abort_unless($employeeDocument->employee_id === $employee->id, 404);

        DB::transaction(function () use ($request, $employee, $employeeDocument): void {
            $employeeDocument->update(['archived_at' => now(), 'employee_visible' => false]);
            $this->audit($request, 'employee_document.archived', $employeeDocument, ['employee_id' => $employee->id]);
        });

        return back()->with('status', 'Document archived. The secured file and history are retained.');
    }

    public function downloadForHr(Request $request, User $employee, EmployeeDocument $employeeDocument): StreamedResponse
    {
        $this->authorizeEmployeeManagement($request->user(), $employee);
        abort_unless($employeeDocument->employee_id === $employee->id, 404);

        return $this->download($request, $employeeDocument);
    }

    public function myDocuments(Request $request): View
    {
        abort_unless($request->user()->employee_no, 403);

        return view('employees.my-documents', [
            'documents' => $request->user()->documents()
                ->where('employee_visible', true)
                ->whereNull('archived_at')
                ->latest()
                ->paginate(20),
        ]);
    }

    public function downloadForEmployee(Request $request, EmployeeDocument $employeeDocument): StreamedResponse
    {
        abort_unless(
            $employeeDocument->employee_id === $request->user()->id
            && $employeeDocument->employee_visible
            && $employeeDocument->archived_at === null,
            404,
        );

        return $this->download($request, $employeeDocument);
    }

    private function authorizeEmployeeManagement(User $actor, User $employee): void
    {
        abort_unless(in_array($actor->role, ['system_admin', 'hr_director', 'hr_manager'], true), 403);
        abort_unless($actor->role !== 'hr_manager' || $employee->manager_id === $actor->id, 403);
    }

    private function download(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $disk = Storage::disk(config('filesystems.private_disk'));
        abort_unless($disk->exists($document->storage_path), 404);
        $this->audit($request, 'employee_document.downloaded', $document, ['employee_id' => $document->employee_id]);

        return $disk->download($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function safeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? 'employee-document';

        return mb_substr($filename, 0, 255);
    }

    /** @param array<string, mixed> $details */
    private function audit(Request $request, string $action, EmployeeDocument $document, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'employee_document',
            'subject_id' => $document->id,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
