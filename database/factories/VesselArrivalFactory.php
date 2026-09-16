<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\VesselArrival;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VesselArrival>
 */
class VesselArrivalFactory extends Factory
{
    protected $model = VesselArrival::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'vessel_name' => 'MV. '.strtoupper(fake()->lastName()),
            'sign_in_date' => now()->subDays(5)->toDateString(),
            'sign_out_date' => null,
            'sign_in_port' => 'BATAM',
            'captain' => fake()->name(),
        ];
    }
}
