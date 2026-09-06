<?php

namespace Database\Factories;

use App\Models\VisitorCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitorCard>
 */
class VisitorCardFactory extends Factory
{
    protected $model = VisitorCard::class;

    public function definition(): array
    {
        return [
            'card_no' => 'VC-'.fake()->unique()->numerify('#####'),
            'status' => 'available',
        ];
    }

    public function inUse(): static
    {
        return $this->state(fn () => ['status' => 'in_use']);
    }
}
