<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->validatedFilters($request);
        $accessibleEmployees = $this->accessibleEmployees();
        $branches = Branch::where('is_active', true);
        $departments = Department::where('is_active', true);

        if (auth()->user()->role === 'hr_manager') {
            $branches->whereIn('id', (clone $accessibleEmployees)->whereNotNull('branch_id')->select('branch_id'));
            $departments->whereIn('name', (clone $accessibleEmployees)->whereNotNull('department')->select('department'));
        }

        return view('employees.index', [
            'employees' => $this->filteredEmployees($filters)->with(['branch', 'manager'])->orderBy('name')->paginate(20)->withQueryString(),
            'branches' => $branches->orderBy('name')->get(),
            'departments' => $departments->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $employees = $this->filteredEmployees($filters)->with('branch')->orderBy('name')->lazy(1000);

        return response()->streamDownload(function () use ($employees): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Employee number', 'Name', 'Work email', 'Phone', 'Job title', 'Employment type', 'Department', 'Branch', 'Joining date', 'Probation ends', 'Contract starts', 'Contract ends', 'Status']);

            foreach ($employees as $employee) {
                fputcsv($output, [
                    $this->safeCsvValue($employee->employee_no),
                    $this->safeCsvValue($employee->name),
                    $this->safeCsvValue($employee->email),
                    $this->safeCsvValue($employee->phone),
                    $this->safeCsvValue($employee->job_title),
                    $this->safeCsvValue($employee->employment_type),
                    $this->safeCsvValue($employee->department),
                    $this->safeCsvValue($employee->branch?->name),
                    $employee->joined_on?->toDateString(),
                    $employee->probation_ends_on?->toDateString(),
                    $employee->contract_starts_on?->toDateString(),
                    $employee->contract_ends_on?->toDateString(),
                    $employee->is_active ? 'Active' : 'Inactive',
                ]);
            }

            fclose($output);
        }, 'employees-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): View
    {
        return view('employees.form', [
            'employee' => new User,
            'branches' => $this->formBranches(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'managers' => User::where('role', 'hr_manager')->where('is_active', true)->orderBy('name')->get(),
            'editing' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'employee_no' => ['nullable', 'string', 'max:80', 'unique:users,employee_no'],
            'department' => ['nullable', 'string', 'max:120'],
            'gender' => ['nullable', 'in:male,female,non_binary,prefer_not_to_say,other'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'contract_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'satisfaction_score' => ['nullable', 'integer', 'between:0,100'],
            'performance_score' => ['nullable', 'integer', 'between:0,100'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'hr_manager')->where('is_active', true)],
            ...$this->employeeProfileRules(),
            'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols(), 'confirmed'],
            'role' => ['sometimes', 'string', Rule::in(['employee', 'hr_manager', 'hr_director', 'attendance_officer', 'payroll_officer', 'finance_officer', 'system_admin'])],
        ]);
        if ($request->user()->role === 'system_admin') {
            $data['role'] ??= 'employee';
        } else {
            $data['role'] = 'employee';
        }
        if ($data['role'] === 'employee' && empty($data['employee_no'])) {
            throw ValidationException::withMessages(['employee_no' => 'An employee account needs an employee number.']);
        }
        $this->validateEmploymentDates($data);

        if ($request->user()->role === 'hr_manager') {
            abort_unless($request->user()->branch_id !== null && $request->user()->department !== null, 403);
            $data['branch_id'] = $request->user()->branch_id;
            $data['department'] = $request->user()->department;
            $data['manager_id'] = $request->user()->id;
        }

        if (isset($data['manager_id']) && ! User::whereKey($data['manager_id'])->where('role', 'hr_manager')->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['manager_id' => 'Choose an active HR manager.']);
        }
        if ($data['role'] === 'hr_manager' && (empty($data['department']) || empty($data['branch_id']))) {
            throw ValidationException::withMessages(['role' => 'Assign a branch and department before creating an HR manager.']);
        }

        $user = DB::transaction(function () use ($data, $request): User {
            $department = $this->resolveDepartment($request, $data['department'] ?? null, $request->user()->role !== 'hr_manager');
            $user = new User;
            $photoPath = isset($data['profile_photo'])
                ? $data['profile_photo']->store('profile-photos', 'local')
                : null;
            $user->forceFill(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'employee_no' => $data['employee_no'], 'department' => $department?->name, 'department_id' => $department?->id, 'branch_id' => $data['branch_id'] ?? null, 'manager_id' => $data['manager_id'] ?? null, 'password' => Hash::make($data['password']), 'role' => $data['role'], 'is_active' => true, 'must_change_password' => true, 'email_verified_at' => now(), 'profile_photo_path' => $photoPath, ...$this->employeeProfileAttributes($data)])->save();
            if (isset($data['contract_document'])) {
                $extension = $data['contract_document']->guessExtension() ?? $data['contract_document']->extension();
                $path = $data['contract_document']->storeAs('employee-documents/'.$user->id, Str::uuid().'.'.$extension, 'local');
                $user->documents()->create([
                    'uploaded_by' => $request->user()->id,
                    'category' => 'Employment contract',
                    'original_filename' => basename(str_replace('\\', '/', $data['contract_document']->getClientOriginalName())),
                    'storage_path' => $path,
                    'mime_type' => $data['contract_document']->getMimeType() ?? 'application/octet-stream',
                    'file_size' => $data['contract_document']->getSize(),
                    'sha256' => hash_file('sha256', $data['contract_document']->getRealPath()),
                    'employee_visible' => false,
                ]);
            }
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'employee.created', 'subject_type' => 'employee', 'subject_id' => $user->id, 'details' => json_encode(['employee_no' => $user->employee_no, 'role' => $user->role, 'manager_id' => $user->manager_id, 'branch_id' => $user->branch_id, 'department_id' => $user->department_id, 'department' => $user->department]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        });

        return redirect()->route('employees.index')->with('status', 'Employee account created. Share the initial password through an approved secure channel.');
    }

    public function edit(User $employee): View
    {
        $this->authorizeEmployeeAccess($employee);

        return view('employees.form', [
            'employee' => $employee,
            'branches' => $this->formBranches(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'managers' => User::where('role', 'hr_manager')->where('is_active', true)->orderBy('name')->get(),
            'editing' => true,
        ]);
    }

    public function update(Request $request, User $employee): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $this->authorizeEmployeeAccess($employee);
        $originalOrganizationalValues = $employee->only(['role', 'manager_id', 'branch_id', 'department_id', 'department']);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$employee->id],
            'employee_no' => ['nullable', 'string', 'max:80', 'unique:users,employee_no,'.$employee->id],
            'department' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'hr_manager')->where('is_active', true)],
            'is_active' => ['required', 'boolean'],
            'gender' => ['nullable', 'in:male,female,non_binary,prefer_not_to_say,other'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'contract_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'satisfaction_score' => ['nullable', 'integer', 'between:0,100'],
            'performance_score' => ['nullable', 'integer', 'between:0,100'],
            ...$this->employeeProfileRules(),
        ];
        if ($request->user()->role === 'system_admin') {
            $rules['password'] = ['nullable', Password::min(12)->letters()->mixedCase()->numbers()->symbols(), 'confirmed'];
        }

        if ($request->user()->role === 'system_admin') {
            $rules['role'] = ['required', 'in:employee,hr_manager,hr_director,attendance_officer,payroll_officer,finance_officer,system_admin'];
        }

        $data = $request->validate($rules);
        $this->validateEmploymentDates($data);

        if ($request->user()->role === 'hr_manager') {
            $data['department'] = $employee->department;
            $data['department_id'] = $employee->department_id;
            $data['branch_id'] = $employee->branch_id;
            $data['manager_id'] = $request->user()->id;
        } else {
            $data['department_id'] = isset($data['department']) ? Department::where('name', $data['department'])->value('id') : null;
        }

        if ($request->user()->role !== 'system_admin') {
            unset($data['role']);
        }
        if (($data['role'] ?? $employee->role) === 'employee' && empty($data['employee_no'])) {
            throw ValidationException::withMessages(['employee_no' => 'An employee account needs an employee number.']);
        }

        if ($employee->role === 'hr_manager' && $employee->directReports()->exists()) {
            $isBeingDemoted = isset($data['role']) && $data['role'] !== 'hr_manager';
            $isBeingDeactivated = ! (bool) $data['is_active'];

            if ($isBeingDemoted || $isBeingDeactivated) {
                throw ValidationException::withMessages(['role' => 'Reassign this manager’s direct reports before changing or deactivating the account.']);
            }
        }

        if (isset($data['role']) && $data['role'] === 'hr_manager' && (empty($data['department']) || empty($data['branch_id']))) {
            throw ValidationException::withMessages(['role' => 'Assign a branch and department before granting HR manager access.']);
        }

        if (($data['role'] ?? $employee->role) === 'system_admin' && ! (bool) ($data['is_active'] ?? $employee->is_active)) {
            throw ValidationException::withMessages(['is_active' => 'An administrator account cannot be deactivated while it has administrator access. Remove the administrator role first.']);
        }

        if ($employee->role === 'system_admin' && ((isset($data['role']) && $data['role'] !== 'system_admin') || ! (bool) ($data['is_active'] ?? $employee->is_active))) {
            $activeAdmins = User::where('role', 'system_admin')->where('is_active', true)->count();
            if ($activeAdmins <= 1) {
                throw ValidationException::withMessages(['role' => 'The last active system administrator cannot be demoted or deactivated. Create another administrator first.']);
            }
        }

        if (isset($data['manager_id']) && $this->wouldCreateReportingCycle($employee, (int) $data['manager_id'])) {
            throw ValidationException::withMessages(['manager_id' => 'That reporting assignment would create a management cycle.']);
        }

        DB::transaction(function () use ($data, $employee, $request, $originalOrganizationalValues): void {
            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
                $data['remember_token'] = Str::random(60);
                $data['must_change_password'] = true;
                if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $employee->id)->delete();
                }
            } else {
                unset($data['password']);
            }
            if (isset($data['profile_photo'])) {
                $oldPhoto = $employee->profile_photo_path;
                $data['profile_photo_path'] = $data['profile_photo']->store('profile-photos/'.$employee->id, 'local');
                unset($data['profile_photo']);
                if ($oldPhoto) {
                    Storage::disk('local')->delete($oldPhoto);
                }
            }
            unset($data['contract_document']);
            $data['email'] = mb_strtolower($data['email']);
            if ($request->user()->role !== 'hr_manager') {
                $department = $this->resolveDepartment($request, $data['department'] ?? null, true);
                $data['department'] = $department?->name;
                $data['department_id'] = $department?->id;
            }

            $employee->forceFill($data)->save();
            $profileFieldsChanged = array_values(array_intersect($this->employeeProfileFieldNames(), array_keys($employee->getChanges())));
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'employee.updated', 'subject_type' => 'employee', 'subject_id' => $employee->id, 'details' => json_encode(['employee_no' => $employee->employee_no, 'original_values' => $originalOrganizationalValues, 'revised_values' => $employee->only(['role', 'manager_id', 'branch_id', 'department_id', 'department']), 'profile_fields_changed' => $profileFieldsChanged, 'password_reset' => array_key_exists('password', $employee->getChanges())]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('employees.index')->with('status', 'Employee profile updated.');
    }

    /** @return array{q: string|null, department: string|null, branch_id: int|null, status: string|null} */
    private function validatedFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'q' => $validated['q'] ?? null,
            'department' => $validated['department'] ?? null,
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            'status' => $validated['status'] ?? null,
        ];
    }

    /** @param array{q: string|null, department: string|null, branch_id: int|null, status: string|null} $filters */
    private function filteredEmployees(array $filters): Builder
    {
        return $this->accessibleEmployees()
            ->when($filters['q'] !== null, function (Builder $query) use ($filters): void {
                $query->where(function (Builder $searchQuery) use ($filters): void {
                    $searchTerm = '%'.$filters['q'].'%';
                    $searchQuery->where('name', 'like', $searchTerm)
                        ->orWhere('email', 'like', $searchTerm)
                        ->orWhere('employee_no', 'like', $searchTerm);
                });
            })
            ->when($filters['department'] !== null, fn (Builder $query) => $query->where('department', $filters['department']))
            ->when($filters['branch_id'] !== null, fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'));
    }

    private function accessibleEmployees(): Builder
    {
        return User::query()
            ->when(auth()->user()->role === 'hr_manager', fn (Builder $query) => $query->whereNotNull('employee_no')->where('manager_id', auth()->id()));
    }

    private function authorizeEmployeeAccess(User $employee): void
    {
        abort_unless(auth()->user()->role !== 'hr_manager' || $employee->manager_id === auth()->id(), 403);
    }

    private function wouldCreateReportingCycle(User $employee, int $managerId): bool
    {
        $visitedManagerIds = [];
        $currentManagerId = $managerId;

        while ($currentManagerId !== null) {
            if ($currentManagerId === $employee->id || in_array($currentManagerId, $visitedManagerIds, true)) {
                return true;
            }

            $visitedManagerIds[] = $currentManagerId;
            $currentManagerId = User::whereKey($currentManagerId)->value('manager_id');
            $currentManagerId = $currentManagerId === null ? null : (int) $currentManagerId;
        }

        return false;
    }

    /** @return array<string, array<int, string>> */
    private function employeeProfileRules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:3000'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:60'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['nullable', 'in:permanent,fixed_term,casual,part_time,intern,consultant,other'],
            'joined_on' => ['nullable', 'date'],
            'probation_ends_on' => ['nullable', 'date'],
            'contract_starts_on' => ['nullable', 'date'],
            'contract_ends_on' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,non_binary,prefer_not_to_say,other'],
            'satisfaction_score' => ['nullable', 'integer', 'between:0,100'],
            'performance_score' => ['nullable', 'integer', 'between:0,100'],
        ];
    }

    /** @param array<string, mixed> $validatedData
     * @return array<string, mixed>
     */
    private function employeeProfileAttributes(array $validatedData): array
    {
        return array_intersect_key($validatedData, array_flip($this->employeeProfileFieldNames()));
    }

    /** @return array<int, string> */
    private function employeeProfileFieldNames(): array
    {
        return [
            'phone', 'address', 'date_of_birth', 'emergency_contact_name', 'emergency_contact_phone',
            'emergency_contact_relationship', 'job_title', 'employment_type', 'joined_on',
            'probation_ends_on', 'contract_starts_on', 'contract_ends_on',
            'gender', 'satisfaction_score', 'performance_score',
        ];
    }

    /** @param array<string, mixed> $validatedData */
    private function validateEmploymentDates(array $validatedData): void
    {
        foreach ([['joined_on', 'probation_ends_on'], ['contract_starts_on', 'contract_ends_on']] as [$startDate, $endDate]) {
            if (! empty($validatedData[$startDate]) && ! empty($validatedData[$endDate]) && CarbonImmutable::parse($validatedData[$endDate])->lt(CarbonImmutable::parse($validatedData[$startDate]))) {
                throw ValidationException::withMessages([$endDate => 'The end date must be on or after its start date.']);
            }
        }
    }

    private function resolveDepartment(Request $request, ?string $departmentName, bool $mayCreate): ?Department
    {
        if ($departmentName === null || trim($departmentName) === '') {
            return null;
        }

        $department = Department::where('name', $departmentName)->lockForUpdate()->first();

        if ($department !== null) {
            if (! $department->is_active) {
                throw ValidationException::withMessages(['department' => 'Choose an active department.']);
            }

            return $department;
        }

        if (! $mayCreate) {
            throw ValidationException::withMessages(['department' => 'Choose a department assigned to your team.']);
        }

        $department = Department::create(['name' => $departmentName, 'is_active' => true]);
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'department.created',
            'subject_type' => Department::class,
            'subject_id' => $department->id,
            'details' => json_encode(['name' => $department->name, 'created_from' => 'employee_profile']),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $department;
    }

    /** @return Collection<int, Branch> */
    private function formBranches(): Collection
    {
        if (auth()->user()->role === 'hr_manager') {
            return Branch::whereKey(auth()->user()->branch_id)->where('is_active', true)->get();
        }

        return Branch::where('is_active', true)->orderBy('name')->get();
    }

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+@\-]/', $value) === 1 ? "'".$value : $value;
    }
}
