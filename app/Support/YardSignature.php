<?php

namespace App\Support;

/**
 * The HMAC-SHA256 contract between this gate and the cloud hub (decisions SEC-1 and SEC-2,
 * docs/vms-hybrid-architecture-spec.md §5.2):
 *
 *   yardCode|keyId|timestamp|nonce|METHOD|path[?canonicalQuery]|sha256hex(rawBody)
 *
 * The key id is signed too, so it cannot be swapped. The query is decoded and re-encoded
 * (RFC 3986, sorted by key then value, byte order) so both sides agree however the client
 * wrote it; "+" is a literal plus, never a space. This class is the hub's verbatim, and
 * tests/Fixtures/hmac-vectors.json is shared byte for byte with the hub repo.
 */
final class YardSignature
{
    public const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    public static function canonical(
        string $yard,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $rawQuery,
        string $body,
    ): string {
        $query = self::canonicalQuery($rawQuery);

        return implode('|', [
            $yard,
            $keyId,
            $timestamp,
            $nonce,
            strtoupper($method),
            $query === '' ? $path : $path.'?'.$query,
            hash('sha256', $body),
        ]);
    }

    public static function canonicalQuery(string $rawQuery): string
    {
        $pairs = [];

        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [rawurldecode($key), rawurldecode($value)];
        }

        usort($pairs, fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return implode('&', array_map(
            fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
            $pairs,
        ));
    }

    public static function sign(string $canonical, string $secret): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function isValidTimestamp(string $timestamp): bool
    {
        return preg_match(self::TIMESTAMP_PATTERN, $timestamp) === 1;
    }
}
