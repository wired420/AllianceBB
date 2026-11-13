<?php

declare(strict_types=1);

namespace AllianceBB\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : match ($status) {
            400 => 'Bad request',
            403 => 'You do not have permission to do that.',
            404 => 'Page not found.',
            405 => 'Method not allowed.',
            419 => 'Your session expired. Go back, reload the page and try again.',
            429 => 'Too many requests. Slow down and try again shortly.',
            default => 'Something went wrong.',
        });
    }
}
