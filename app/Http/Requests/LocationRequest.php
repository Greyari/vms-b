<?php

namespace App\Http\Requests;

use App\Enums\LocationType;
use App\Models\Location;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A vessel or a place as the location master sends it (POST and PUT
 * /locations). A vessel is keyed by the IMO number typed in. A place gets its
 * code from its name when it is created (Location::codeFromName()) and keeps
 * it when renamed, as in VMS-old. Authorization is the route's permission
 * middleware.
 */
class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->existingLocation();
        $isPlace = $this->input('type') === LocationType::Location->value;

        return [
            'type' => ['required', Rule::enum(LocationType::class)],
            'name' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($location, $isPlace): void {
                    // A new place takes its code from the name, so two places
                    // may not share a name. The unique index also counts
                    // deleted rows.
                    if ($location === null && $isPlace && is_string($value)
                        && Location::withTrashed()->where('code', Location::codeFromName($value))->exists()) {
                        $fail('A location with this name already exists.');
                    }
                },
            ],
            'code' => [
                Rule::requiredIf(! $isPlace),
                'nullable', 'string', 'max:255',
                Rule::unique('locations', 'code')->ignore($location?->id),
                function (string $attribute, mixed $value, Closure $fail) use ($location, $isPlace): void {
                    if ($isPlace && $value !== null && $value !== $location?->code) {
                        $fail('A place code comes from its name when the place is created and cannot be set.');
                    }
                },
            ],
            'call_sign' => ['nullable', 'string', 'max:255'],
            'sign' => ['nullable', 'string', 'max:255'],
            'flag' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'string', 'max:255'],
            'vessel_type' => ['nullable', 'string', 'max:255'],
            'captain' => ['nullable', 'string', 'max:255'],
            'pic' => ['nullable', 'string', 'max:255'],
            'local_agent_company' => ['nullable', 'string', 'max:255'],
            'local_agent_name' => ['nullable', 'string', 'max:255'],
            'local_agent_phone' => ['nullable', 'string', 'max:255'],
            'local_agent_location' => ['nullable', 'string', 'max:255'],
            'foreign_agent_company' => ['nullable', 'string', 'max:255'],
            'foreign_agent_name' => ['nullable', 'string', 'max:255'],
            'foreign_agent_phone' => ['nullable', 'string', 'max:255'],
            'foreign_agent_location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** The location being updated, or null when one is being created. */
    private function existingLocation(): ?Location
    {
        $id = $this->route('id');

        return $id === null ? null : Location::find($id);
    }
}
