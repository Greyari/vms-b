<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'code' => 'IMO'.fake()->unique()->numerify('#######'),
            'name' => 'MV. '.strtoupper(fake()->lastName()),
            'type' => LocationType::RepairVessel->value,
            'is_active' => true,
        ];
    }

    public function place(string $name = 'PRODUCTION OFFICE'): static
    {
        return $this->state(fn () => [
            'code' => null,
            'name' => $name,
            'type' => LocationType::Location->value,
        ]);
    }
}
