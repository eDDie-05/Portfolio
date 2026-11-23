<?php

namespace Database\Factories;

use App\Models\OffboardingCase;
use App\Models\OffboardingClearanceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffboardingClearanceItem>
 */
class OffboardingClearanceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offboarding_case_id' => OffboardingCase::factory(),
            'assigned_to' => User::factory()->state([
                'role' => 'employee',
                'employee_no' => fake()->unique()->bothify('EMP-#####'),
                'is_active' => true,
            ]),
            'title' => fake()->sentence(4),
            'department' => fake()->randomElement(['IT', 'Finance', 'People and Culture']),
            'instructions' => fake()->sentence(),
            'due_on' => fake()->dateTimeBetween('today', '+30 days')->format('Y-m-d'),
            'status' => 'pending',
        ];
    }
}
