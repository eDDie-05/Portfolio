<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->distinct()
            ->pluck('department')
            ->each(function (string $departmentName): void {
                $department = Department::firstOrCreate(['name' => $departmentName]);
                User::where('department', $departmentName)->update(['department_id' => $department->id]);
            });
    }
}
