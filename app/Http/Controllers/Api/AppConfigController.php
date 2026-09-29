<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Per-yard settings the SPA needs at runtime (decision PE-7): whose name, address and logo go on the
 * printed pass. Public — the login page may show the company too, and nothing here is secret.
 */
class AppConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $branding = config('vms.branding');

        return response()->json([
            'company_name' => (string) ($branding['company_name'] ?? ''),
            'company_address' => (string) ($branding['company_address'] ?? ''),
            'logo_url' => $this->logoUrl($branding['logo'] ?? null),
            'yard_code' => (string) config('vms.sync.yard_code'),
        ]);
    }

    /** Only a plain file name inside public/branding/, and only when the file is really there. */
    private function logoUrl(?string $logo): ?string
    {
        if ($logo === null || $logo === '' || basename($logo) !== $logo) {
            return null;
        }

        return is_file(public_path('branding'.DIRECTORY_SEPARATOR.$logo)) ? '/branding/'.rawurlencode($logo) : null;
    }
}
