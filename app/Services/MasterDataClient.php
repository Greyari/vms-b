<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fase 4 — read-only Master Data lookups for VMS: visitor auto-fill by
 * KTP/passport and the central blacklist gate. Authenticates as the
 * `svc-vms` service account (Viewer role — lookups only).
 *
 * No-ops while `services.master_data.enabled` is false, so switching the flag
 * off restores standalone behaviour instantly.
 */
class MasterDataClient
{
    private const TOKEN_CACHE_KEY = 'master-data:svc-token';

    public function enabled(): bool
    {
        return (bool) config('services.master_data.enabled')
            && config('services.master_data.password') !== null;
    }

    /**
     * Look up a person by identity document. Returns the master person array
     * (camelCase keys incl. fullName / isBlacklisted / blacklistReason) or
     * null when unknown. Throws on connectivity failure — callers decide
     * whether to fail open or closed.
     *
     * @return array<string, mixed>|null
     */
    public function lookupPerson(string $idType, string $idNumber): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $param = match ($idType) {
            'ktp' => 'ktp',
            'passport' => 'passport',
            default => null,       // SIM etc. — master has no such index
        };

        if ($param === null || trim($idNumber) === '') {
            return null;
        }

        $lookup = fn (string $token) => Http::baseUrl(config('services.master_data.base_url'))
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout(3)
            ->withToken($token)
            ->get('/people/lookup', [$param => trim($idNumber)]);

        $response = $lookup($this->token());

        // A cached token can outlive its server-side validity (rotation,
        // revocation). On a 401, forget the cached token and retry once with a
        // freshly minted one before giving up.
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $lookup($this->token());
        }

        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json('data');
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function (): string {
            try {
                $response = Http::baseUrl(config('services.master_data.base_url'))
                    ->acceptJson()
                    ->connectTimeout(2)
                    ->timeout(3)
                    ->post('/auth/login', [
                        'email' => config('services.master_data.email'),
                        'password' => config('services.master_data.password'),
                    ])
                    ->throw();
            } catch (RequestException $e) {
                throw new \RuntimeException('Master Data login failed: '.$e->getMessage(), previous: $e);
            }

            return (string) $response->json('accessToken');
        });
    }
}
