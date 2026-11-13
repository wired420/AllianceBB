<?php

declare(strict_types=1);

namespace AllianceBB\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['content-type' => 'text/html; charset=utf-8'],
        /** @var list<string> */
        public array $cookies = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['content-type' => 'application/json'],
        );
    }

    public static function redirect(string $url, int $status = 303): self
    {
        return new self('', $status, ['location' => $url]);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['content-type' => 'text/plain; charset=utf-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    public function withCookie(string $name, string $value, int $expires, bool $secure): self
    {
        $this->cookies[] = sprintf(
            '%s=%s; Path=/; Expires=%s; HttpOnly; SameSite=Lax%s',
            $name,
            rawurlencode($value),
            gmdate('D, d M Y H:i:s \G\M\T', $expires),
            $secure ? '; Secure' : '',
        );
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            foreach ($this->cookies as $cookie) {
                header('set-cookie: ' . $cookie, false);
            }
        }
        echo $this->body;
    }
}
