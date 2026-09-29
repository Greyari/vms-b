<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PreRegistration;
use Illuminate\Http\JsonResponse;

/**
 * Looks up a scanned pre-registration in the local mirror (hybrid spec §6.2, decision FE-4a). No
 * internet involved: a token this gate has not pulled yet answers 404 and the visitor is served as a
 * walk-in.
 */
class PreRegistrationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $preRegistration = PreRegistration::firstWhere('token', PreRegistration::normalizeToken(urldecode($token)));
        abort_if($preRegistration === null, 404, 'This pre-registration is not known at this gate yet.');

        return response()->json(['data' => [
            'token' => $preRegistration->token,
            'id_type' => $preRegistration->id_type,
            'id_number' => $preRegistration->id_number,
            'name' => $preRegistration->name,
            'company' => $preRegistration->company,
            'phone' => $preRegistration->phone,
            'gender' => $preRegistration->gender,
            'purpose' => $preRegistration->purpose,
            'host_name' => $preRegistration->host_name,
            'host_department' => $preRegistration->host_department,
            'scheduled_date' => $preRegistration->scheduled_date->toDateString(),
            'vehicle_no' => $preRegistration->vehicle_no,
            'status' => $preRegistration->effectiveStatus()->value,
        ]]);
    }
}
