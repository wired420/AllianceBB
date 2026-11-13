<?php

declare(strict_types=1);

namespace AllianceBB\Federation;

use RuntimeException;

/** An error answered to another site as JSON: {"error": code, "message": text}. */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
