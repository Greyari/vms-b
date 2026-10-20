<?php

namespace App\Http\Requests;

use App\Enums\LocationType;
use App\Enums\VisitorType;
use App\Support\IdentityNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The visitor profile as the registration and edit forms send it (POST and
 * PUT /visitors). The identity number is normalized before validation, so the
 * unique rule compares the stored spelling. Fields a request leaves out stay
 * as they are. Authorization is the route's permission middleware.
 */
class VisitorProfileRequest extends FormRequest
{
    public const DUPLICATE_IDENTITY = 'A visitor with this ID type and number is already registered.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('id_number'))) {
            $this->merge(['id_number' => IdentityNumber::normalize($this->input('id_number'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_type' => ['required', Rule::in(['ktp', 'sim', 'passport'])],
            // Identity is (id_type, id_number): a clash is a 422 here, not a
            // database error later.
            'id_number' => [
                'required', 'string', 'max:255',
                Rule::unique('visitors', 'id_number')
                    ->where(fn ($query) => $query->where('id_type', $this->input('id_type')))
                    ->ignore($this->route('id')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'visitor_type' => ['sometimes', Rule::enum(VisitorType::class)],
            'gender' => ['nullable', Rule::in(['M', 'F', 'Male', 'Female', 'male', 'female', 'L', 'P', 'laki-laki', 'perempuan', 'pria', 'wanita'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'id_issued_at' => ['nullable', 'date'],
            'id_expires_at' => ['nullable', 'date'],
            'id_lifetime' => ['sometimes', 'boolean'],
            // A crew member's ship: a vessel from the location master, not a place.
            'vessel_id' => [
                'nullable', 'uuid',
                Rule::exists('locations', 'id')
                    ->whereNot('type', LocationType::Location->value)
                    ->whereNull('deleted_at'),
            ],
            'certificate' => ['nullable', 'string', 'max:255'],
            'certificate_no' => ['nullable', 'string', 'max:255'],
            'seaman_book_no' => ['nullable', 'string', 'max:255'],
            'seaman_book_expires_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['id_number.unique' => self::DUPLICATE_IDENTITY];
    }
}
