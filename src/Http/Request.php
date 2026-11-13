<?php

declare(strict_types=1);

namespace AllianceBB\Http;

final class Request
{
    /** @var array<string, string> route parameters filled in by the router */
    public array $params = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     * @param array<string, string> $headers lower-case header names
     * @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $files = [],
        public readonly string $scheme = 'http',
        public readonly string $host = 'localhost',
        public readonly string $ip = '127.0.0.1',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $scheme = $https ? 'https' : (($headers['x-forwarded-proto'] ?? '') === 'https' ? 'https' : 'http');

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: '/' . ltrim(rawurldecode($path), '/'),
            query: $_GET,
            post: $_POST,
            cookies: $_COOKIE,
            headers: $headers,
            body: (string) file_get_contents('php://input'),
            files: $_FILES,
            scheme: $scheme,
            host: (string) ($headers['host'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'),
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function intParam(string $name): int
    {
        return (int) $this->param($name);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json');
    }

    public function baseUrl(): string
    {
        return $this->scheme . '://' . $this->host;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }
}
