<?php

declare(strict_types=1);

namespace AllianceBB\Core;

/** UUIDv7: time-ordered, so IDs created on any site sort roughly by creation time. */
final class Uuid
{
    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $ms << 16) . random_bytes(10);
        $bytes = substr($bytes, 0, 6) . substr($bytes, 8);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
