<?php

namespace Database\Factories;

use App\Models\OffboardingCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffboardingCase>
 */
class OffboardingCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => User::factory()->state([
                'role' => 'employee',
                'employee_no' => fake()->unique()->bothify('EMP-#####'),
                'is_active' => true,
            ]),
            'owner_id' => User::factory()->state(['role' => 'hr_director', 'is_active' => true]),
            'created_by' => User::factory()->state(['role' => 'system_admin', 'is_active' => true]),
            'reason' => fake()->randomElement(['resignation', 'termination', 'retirement', 'end_of_contract', 'other']),
            'last_working_day' => fake()->dateTimeBetween('today', '+60 days')->format('Y-m-d'),
            'status' => 'in_progress',
            'exit_interview_status' => 'not_scheduled',
            'final_payroll_status' => 'pending',
        ];
    }
}
