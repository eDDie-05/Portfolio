<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(): View
    {
        return view('departments.index', ['departments' => Department::withCount('employees')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:departments,name'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $department = DB::transaction(function () use ($data, $request): Department {
            $department = Department::create($data + ['is_active' => true]);
            $this->recordAudit($request, 'department.created', $department, ['name' => $department->name]);

            return $department;
        });

        return redirect()->route('departments.index')->with('status', $department->name.' department created.');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $departmentName = DB::transaction(function () use ($data, $department, $request): string {
            $department = Department::whereKey($department->id)->lockForUpdate()->firstOrFail();
            $originalName = $department->name;
            $department->update($data);

            if ($originalName !== $department->name) {
                $department->employees()->update(['department' => $department->name]);
            }

            $this->recordAudit($request, 'department.updated', $department, [
                'original_name' => $originalName,
                'revised_name' => $department->name,
                'employee_count' => $department->employees()->count(),
            ]);

            return $department->name;
        });

        return redirect()->route('departments.index')->with('status', $departmentName.' department updated.');
    }

    public function deactivate(Request $request, Department $department): RedirectResponse
    {
        DB::transaction(function () use ($department, $request): void {
            $department = Department::whereKey($department->id)->lockForUpdate()->firstOrFail();

            if ($department->employees()->exists()) {
                throw ValidationException::withMessages(['department' => 'Move all assigned employees before deactivating this department.']);
            }

            $department->update(['is_active' => false]);
            $this->recordAudit($request, 'department.deactivated', $department, ['name' => $department->name]);
        });

        return redirect()->route('departments.index')->with('status', $department->name.' department deactivated.');
    }

    public function activate(Request $request, Department $department): RedirectResponse
    {
        DB::transaction(function () use ($department, $request): void {
            $department->update(['is_active' => true]);
            $this->recordAudit($request, 'department.activated', $department, ['name' => $department->name]);
        });

        return redirect()->route('departments.index')->with('status', $department->name.' department activated.');
    }

    /** @param array<string, mixed> $details */
    private function recordAudit(Request $request, string $action, Department $department, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Department::class,
            'subject_id' => $department->id,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
