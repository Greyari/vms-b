<?php

namespace App\Http\Requests;

use App\Models\VesselArrival;
use Illuminate\Foundation\Http\FormRequest;

class VesselArrivalRequest extends FormRequest
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
        $isPost = $this->isMethod('post');

        $signInDate = $this->input('sign_in_date');
        if (! $signInDate && ! $isPost) {
            $arrivalParam = $this->route('vessel_arrival') ?? $this->route('id');
            if ($arrivalParam instanceof VesselArrival) {
                $signInDate = $arrivalParam->sign_in_date?->format('Y-m-d');
            } elseif (is_string($arrivalParam)) {
                $signInDate = VesselArrival::find($arrivalParam)?->sign_in_date?->format('Y-m-d');
            }
        }

        $signOutRules = ['nullable', 'date'];
        if ($this->has('sign_in_date')) {
            $signOutRules[] = 'after_or_equal:sign_in_date';
        } elseif ($signInDate) {
            $signOutRules[] = 'after_or_equal:'.$signInDate;
        }

        return [
            'location_id' => [$isPost ? 'required' : 'sometimes', 'uuid', 'exists:locations,id'],
            'sign_in_date' => [$isPost ? 'required' : 'sometimes', 'date'],
            'sign_in_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'sign_out_date' => $signOutRules,
            'sign_out_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'sign_in_port' => ['nullable', 'string', 'max:255'],
            'sign_out_port' => ['nullable', 'string', 'max:255'],
            'captain' => ['nullable', 'string', 'max:255'],
            'local_agent_company' => ['nullable', 'string', 'max:255'],
            'local_agent_name' => ['nullable', 'string', 'max:255'],
            'local_agent_phone' => ['nullable', 'string', 'max:255'],
            'local_agent_location' => ['nullable', 'string', 'max:255'],
            'foreign_agent_company' => ['nullable', 'string', 'max:255'],
            'foreign_agent_name' => ['nullable', 'string', 'max:255'],
            'foreign_agent_phone' => ['nullable', 'string', 'max:255'],
            'foreign_agent_location' => ['nullable', 'string', 'max:255'],
            'crew_ids' => ['sometimes', 'array'],
            'crew_ids.*' => ['uuid', 'exists:visitors,id'],
        ];
    }
}
