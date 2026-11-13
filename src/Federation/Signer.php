<?php

declare(strict_types=1);

namespace AllianceBB\Federation;

/**
 * HMAC-SHA256 request signing shared by both ends of a connection.
 * The signature covers the method, path with query, timestamp, a nonce and
 * the body hash, so a request can't be altered or replayed.
 */
final class Signer
{
    public const MAX_SKEW = 300;

    /** @return array<string, string> headers to send */
    public static function headers(string $publicId, string $secret, string $method, string $pathWithQuery, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        return [
            'X-Alliance-Key' => $publicId,
            'X-Alliance-Timestamp' => $timestamp,
            'X-Alliance-Nonce' => $nonce,
            'X-Alliance-Signature' => self::sign($secret, $method, $pathWithQuery, $timestamp, $nonce, $body),
        ];
    }

    public static function sign(string $secret, string $method, string $pathWithQuery, string $timestamp, string $nonce, string $body): string
    {
        $base = strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $base, $secret, true)), '+/', '-_'), '=');
    }

    public static function verify(string $secret, string $method, string $pathWithQuery, string $timestamp, string $nonce, string $body, string $signature): bool
    {
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW) {
            return false;
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return false;
        }
        return hash_equals(self::sign($secret, $method, $pathWithQuery, $timestamp, $nonce, $body), $signature);
    }

    public static function newPublicId(): string
    {
        return 'abb_' . bin2hex(random_bytes(12));
    }

    public static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
