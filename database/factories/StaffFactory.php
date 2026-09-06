<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'staff_id' => 'STF-'.fake()->unique()->numerify('######'),
            'name' => fake()->name(),
            'department' => fake()->randomElement(['IT', 'HR', 'Operations', 'Finance', 'Security']),
        ];
    }
}
