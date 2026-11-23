<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('branches.index', [
            'branches' => Branch::withCount(['employees', 'devices', 'registeredComputers'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:branches,name'],
            'timezone' => ['required', 'timezone'],
        ]);

        $branch = DB::transaction(function () use ($data, $request): Branch {
            $branch = Branch::create($data + ['is_active' => true]);
            $this->recordAudit($request, 'branch.created', $branch, ['name' => $branch->name, 'timezone' => $branch->timezone]);

            return $branch;
        });

        return redirect()->route('branches.index')->with('status', $branch->name.' branch created.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('branches', 'name')->ignore($branch->id)],
            'timezone' => ['required', 'timezone'],
        ]);

        $updatedName = DB::transaction(function () use ($data, $branch, $request): string {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $originalValues = $branch->only(['name', 'timezone']);
            $branch->update($data);
            $this->recordAudit($request, 'branch.updated', $branch, [
                'original_values' => $originalValues,
                'revised_values' => $branch->only(['name', 'timezone']),
            ]);

            return $branch->name;
        });

        return redirect()->route('branches.index')->with('status', $updatedName.' branch updated.');
    }

    public function deactivate(Request $request, Branch $branch): RedirectResponse
    {
        DB::transaction(function () use ($branch, $request): void {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();

            if ($branch->employees()->exists() || $branch->devices()->exists() || $branch->registeredComputers()->exists()) {
                throw ValidationException::withMessages(['branch' => 'Move employees, devices, and registered computers before deactivating this branch.']);
            }

            $branch->update(['is_active' => false]);
            $this->recordAudit($request, 'branch.deactivated', $branch, ['name' => $branch->name]);
        });

        return redirect()->route('branches.index')->with('status', $branch->name.' branch deactivated.');
    }

    public function activate(Request $request, Branch $branch): RedirectResponse
    {
        DB::transaction(function () use ($branch, $request): void {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $branch->update(['is_active' => true]);
            $this->recordAudit($request, 'branch.activated', $branch, ['name' => $branch->name]);
        });

        return redirect()->route('branches.index')->with('status', $branch->name.' branch activated.');
    }

    /** @param array<string, mixed> $details */
    private function recordAudit(Request $request, string $action, Branch $branch, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Branch::class,
            'subject_id' => $branch->id,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
