<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Database;
use AllianceBB\Http\HttpException;

/** Limits repeated failed sign-ins, for the login form and the API token endpoint. */
final class Throttle
{
    /** Failures allowed per account name, and per address (higher, since people can share one). */
    public const MAX_FAILURES = 10;
    public const MAX_FAILURES_PER_IP = 30;
    public const WINDOW_MINUTES = 15;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param list<string> $keys @throws HttpException 429 when any key has too many recent failures */
    public function check(array $keys): void
    {
        foreach ($keys as $key) {
            $count = (int) $this->db->value(
                "SELECT count(*) FROM auth_failures WHERE key = ? AND created_at > now() - make_interval(mins => ?)",
                [$key, self::WINDOW_MINUTES],
            );
            if ($count >= (str_starts_with($key, 'ip:') ? self::MAX_FAILURES_PER_IP : self::MAX_FAILURES)) {
                throw new HttpException(429, 'Too many failed sign-ins. Try again in ' . self::WINDOW_MINUTES . ' minutes.');
            }
        }
    }

    /** @param list<string> $keys */
    public function fail(array $keys): void
    {
        foreach ($keys as $key) {
            $this->db->run('INSERT INTO auth_failures (key) VALUES (?)', [$key]);
        }
        if (random_int(1, 50) === 1) {
            $this->db->run("DELETE FROM auth_failures WHERE created_at < now() - interval '1 day'");
        }
    }

    /** @param list<string> $keys */
    public function clear(array $keys): void
    {
        foreach ($keys as $key) {
            $this->db->run('DELETE FROM auth_failures WHERE key = ?', [$key]);
        }
    }

    /** @return list<string> */
    public static function keys(string $ip, string $login): array
    {
        return ['ip:' . $ip, 'login:' . mb_strtolower(trim($login))];
    }
}
