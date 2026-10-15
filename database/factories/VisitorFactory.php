<?php

namespace Database\Factories;

use App\Enums\VisitorType;
use App\Models\Location;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    public function definition(): array
    {
        return [
            'id_type' => 'ktp',
            'id_number' => (string) fake()->unique()->numerify('################'), // 16 digits
            'name' => fake()->name(),
            'company' => fake()->company(),
            'is_blacklisted' => false,
            'blacklist_reason' => null,
        ];
    }

    public function blacklisted(string $reason = 'Prior security incident'): static
    {
        return $this->state(fn () => [
            'is_blacklisted' => true,
            'blacklist_reason' => $reason,
        ]);
    }

    public function crew(?Location $vessel = null): static
    {
        return $this->state(fn () => [
            'visitor_type' => VisitorType::Crew->value,
            'vessel_id' => $vessel?->id ?? Location::factory(),
        ]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Visitor $visitor) {
            if ($visitor->is_blacklisted && ! $visitor->blacklists()->exists()) {
                $visitor->blacklists()->create([
                    'name' => $visitor->name,
                    'badge_code' => $visitor->badge_code,
                    'id_number' => $visitor->id_number,
                    'offences' => $visitor->offences ?: ($visitor->blacklist_reason ?: 'Prior security incident'),
                    'blacklisted_at' => $visitor->blacklisted_at ?? now()->toDateString(),
                    'remark' => $visitor->remark,
                    'company' => $visitor->company,
                    'is_active' => true,
                ]);
            }
        });
    }
}
