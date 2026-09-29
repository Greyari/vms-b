<?php

namespace App\Services\CloudHub;

use App\Support\YardSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Signed calls from this gate to the cloud hub (hybrid spec §5.1, §5.2). Every request carries the
 * yard code, key id, a UTC timestamp, a fresh nonce and the HMAC over exactly the path, query and body
 * bytes sent. Answers the hub gives on purpose (2xx, 403, 404, 409, 422) come back as responses; anything
 * that means "try again later" throws CloudHubUnavailable.
 */
class CloudHubClient
{
    public function __construct(
        private readonly string $hubUrl,
        private readonly string $yardCode,
        private readonly string $keyId,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('vms.sync');

        return new self(
            (string) $config['hub_url'],
            (string) $config['yard_code'],
            (string) $config['key_id'],
            (string) $config['secret'],
            (int) ($config['timeout_seconds'] ?? 15),
        );
    }

    /** @param array<string, scalar> $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, $query, '');
    }

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload): Response
    {
        return $this->send('POST', $path, [], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, scalar> $query */
    private function send(string $method, string $path, array $query, string $body): Response
    {
        $rawQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = $this->hubUrl.$path.($rawQuery === '' ? '' : '?'.$rawQuery);
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $nonce = (string) Str::uuid();

        $signature = YardSignature::sign(YardSignature::canonical(
            $this->yardCode, $this->keyId, $timestamp, $nonce, $method,
            (string) parse_url($url, PHP_URL_PATH), $rawQuery, $body,
        ), $this->secret);

        $request = Http::timeout($this->timeoutSeconds)
            ->connectTimeout(min(5, $this->timeoutSeconds))
            ->acceptJson()
            ->withHeaders([
                'X-Yard-Code' => $this->yardCode,
                'X-Yard-Key-Id' => $this->keyId,
                'X-Yard-Timestamp' => $timestamp,
                'X-Yard-Nonce' => $nonce,
                'X-Yard-Signature' => $signature,
            ]);

        try {
            $response = $method === 'GET'
                ? $request->get($url)
                : $request->withBody($body, 'application/json')->send($method, $url);
        } catch (ConnectionException $e) {
            throw new CloudHubUnavailable($e->getMessage(), previous: $e);
        }

        if ($response->serverError() || in_array($response->status(), [401, 429], true)) {
            $message = (string) ($response->json('message') ?? '');
            throw new CloudHubUnavailable(trim("Hub answered {$response->status()} {$message}"));
        }

        return $response;
    }
}
