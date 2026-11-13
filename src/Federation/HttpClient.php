<?php

declare(strict_types=1);

namespace AllianceBB\Federation;

use RuntimeException;

/** Outbound HTTP for talking to other AllianceBB sites. */
final class HttpClient
{
    private const MAX_BODY = 8 * 1024 * 1024;

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    public function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        $this->assertAllowedUrl($url);
        $ch = curl_init($url);
        $headerLines = ['Accept: application/json', 'User-Agent: AllianceBB/' . \AllianceBB\App::VERSION];
        if ($body !== '') {
            $headerLines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $received = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$received): int {
                $received .= $chunk;
                return strlen($received) > self::MAX_BODY ? 0 : strlen($chunk);
            },
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new RuntimeException("Could not reach $url: $error");
        }
        $json = json_decode($received, true);
        return ['status' => $status, 'body' => $received, 'json' => is_array($json) ? $json : null];
    }

    /**
     * Refuse private and local addresses unless ALLIANCE_ALLOW_PRIVATE=1, so a
     * stranger in public mode can't make this server call internal services.
     */
    public function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Site addresses must start with http:// or https://');
        }
        if (getenv('ALLIANCE_ALLOW_PRIVATE') === '1') {
            return;
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new RuntimeException("Could not find the address of $host");
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException("$host points to a private address. Set ALLIANCE_ALLOW_PRIVATE=1 to allow it.");
            }
        }
    }
}
