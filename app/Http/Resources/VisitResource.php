<?php

namespace App\Http\Resources;

use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one JSON shape of a visit. It carries what the gate screens, the pass
 * and the reports show — and nothing from the visitor's profile beyond the
 * identity printed on the pass (no birth data, phone, nationality, crew
 * certificates or seaman book).
 *
 * @mixin Visit
 */
class VisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'legacy_registration_no' => $this->legacy_registration_no,
            'date' => $this->date,
            'time_in' => self::clock($this->time_in),
            'time_out' => self::clock($this->time_out),
            // Local wall-clock time, like date and time_in. Eloquent's default
            // serialisation would send UTC and move an early-morning checkout
            // to the previous day.
            'checked_in_at' => $this->date && $this->time_in ? Carbon::parse($this->date.' '.$this->time_in)->format('Y-m-d H:i:s') : $this->created_at?->format('Y-m-d H:i:s'),
            'checked_out_at' => $this->checked_out_at?->format('Y-m-d H:i:s'),
            'purpose' => $this->purpose,
            'vehicle_no' => $this->vehicle_no,
            'plate_normalized' => $this->plate_normalized ?? Visit::normalizePlate($this->vehicle_no),
            'vehicle_card_id' => $this->vehicle_card_id,
            'visitor_name' => $this->visitor?->name,
            'visitor_company' => $this->visitor_company ?: $this->visitor?->company,
            'staff_id' => $this->staff_id,
            'host_name' => $this->host_name ?: $this->staff?->name,
            'host_occupation' => $this->staff?->occupation,
            'host_department' => $this->staff?->department,
            'location_id' => $this->location_id,
            'location_name' => $this->location_name ?: $this->location?->name,
            'location_code' => $this->location?->code,
            'created_by' => $this->created_by,
            'created_by_name' => $this->created_by_name ?: ($this->creator?->name ?? $this->creator?->username),
            'checked_out_by' => $this->checked_out_by,
            'checked_out_by_name' => $this->checked_out_by_name,
            'days_open' => $this->date === null ? 0 : (
                $this->checked_out_at === null
                    ? (int) Carbon::parse($this->date)->diffInDays(now()->startOfDay())
                    : (int) Carbon::parse($this->date)->diffInDays($this->checked_out_at->startOfDay())
            ),
            'visitor' => $this->whenLoaded('visitor', fn (): array => [
                'id' => $this->visitor->id,
                'visitor_no' => $this->visitor->visitor_no,
                'name' => $this->visitor->name,
                'id_type' => $this->visitor->id_type,
                'id_number' => $this->visitor->id_number,
                'company' => $this->visitor->company,
                'visitor_type' => $this->visitor->visitor_type,
                'occupation' => $this->visitor->occupation,
                'vessel_id' => $this->visitor->vessel_id,
            ]),
            'staff' => $this->whenLoaded('staff', fn (): ?array => $this->staff === null ? null : [
                'id' => $this->staff->id,
                'staff_id' => $this->staff->staff_id,
                'name' => $this->staff->name,
                'department' => $this->staff->department,
                'occupation' => $this->staff->occupation,
                'company' => $this->staff->company,
                'address' => $this->staff->address,
                'gender' => $this->staff->gender,
            ]),
            'location' => $this->whenLoaded('location', fn (): ?array => $this->location === null ? null : [
                'id' => $this->location->id,
                'code' => $this->location->code,
                'name' => $this->location->name,
                'type' => $this->location->type,
                'vessel_type' => $this->location->vessel_type,
                'call_sign' => $this->location->call_sign,
                'flag' => $this->location->flag,
                'port' => $this->location->port,
                'captain' => $this->location->captain,
                'pic' => $this->location->pic,
            ]),
            'visitor_cards' => $this->whenLoaded('visitorCards', fn (): array => $this->visitorCards
                ->map(fn ($card): array => ['id' => $card->id, 'card_no' => $card->card_no])
                ->all()),
            'vehicle_card' => $this->whenLoaded('vehicleCard', fn (): ?array => $this->vehicleCard === null ? null : [
                'id' => $this->vehicleCard->id,
                'card_no' => $this->vehicleCard->card_no,
            ]),
        ];
    }

    private static function clock(?string $time): ?string
    {
        return $time === null ? null : Carbon::parse($time)->format('H:i');
    }
}
