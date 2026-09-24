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
            'sign_out_port' => null,
            'captain' => fake()->name(),
            'local_agent_company' => 'PT. ASL SHIPYARD INDONESIA',
            'local_agent_name' => fake()->name(),
            'local_agent_phone' => fake()->phoneNumber(),
            'local_agent_location' => 'BATAM',
            'foreign_agent_company' => 'SINGAPORE MARINE PTE LTD',
            'foreign_agent_name' => fake()->name(),
            'foreign_agent_phone' => fake()->phoneNumber(),
            'foreign_agent_location' => 'SINGAPORE',
        ];
    }

    public function signedOut(?string $date = null): static
    {
        return $this->state(fn () => [
            'sign_out_date' => $date ?? now()->toDateString(),
            'sign_out_port' => 'SINGAPORE',
        ]);
    }
}
