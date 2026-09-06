<?php

namespace Database\Factories;

use App\Models\VehicleCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleCard>
 */
class VehicleCardFactory extends Factory
{
    protected $model = VehicleCard::class;

    public function definition(): array
    {
        return [
            'card_no' => 'VH-'.fake()->unique()->numerify('#####'),
            'status' => 'available',
        ];
    }

    public function inUse(): static
    {
        return $this->state(fn () => ['status' => 'in_use']);
    }
}
