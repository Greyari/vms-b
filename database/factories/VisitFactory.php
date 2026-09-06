<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    /** Sequence base kept high so factory tickets never collide with the
     *  controller's year-scoped 00001.. auto-numbering under test. */
    protected static int $seq = 10000;

    public function definition(): array
    {
        $ticket = Carbon::now()->format('y/m/').str_pad((string) static::$seq++, 5, '0', STR_PAD_LEFT);

        return [
            'visitor_id' => Visitor::factory(),
            'staff_id' => Staff::factory(),
            'vehicle_card_id' => null,
            'purpose' => fake()->sentence(3),
            'date' => Carbon::now()->toDateString(),
            'time_in' => Carbon::now()->toTimeString(),
            'time_out' => null,
            'vehicle_no' => null,
            'ticket_number' => $ticket,
        ];
    }

    public function checkedOut(): static
    {
        return $this->state(fn () => ['time_out' => Carbon::now()->toTimeString()]);
    }
}
