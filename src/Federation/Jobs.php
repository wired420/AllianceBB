<?php

declare(strict_types=1);

namespace AllianceBB\Federation;

use AllianceBB\Core\Database;
use Throwable;

/**
 * A small job queue in PostgreSQL. Workers claim jobs with SKIP LOCKED, so
 * several can run side by side. Failed jobs retry with growing delays.
 */
final class Jobs
{
    public const MAX_ATTEMPTS = 12;

    /** @var array<string, callable(array<string, mixed>): void> */
    private array $handlers = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function handle(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Queue a job. Jobs with the same type and dedupe key are merged while pending,
     * e.g. ten new posts produce one webhook per subscriber instead of ten.
     * @param array<string, mixed> $payload
     */
    public function push(string $type, array $payload, ?string $dedupeKey = null, int $delaySeconds = 0): void
    {
        $this->db->run(
            "INSERT INTO jobs (type, dedupe_key, payload, run_at) VALUES (?, ?, ?::jsonb, now() + make_interval(secs => ?))
             ON CONFLICT (type, dedupe_key) WHERE state = 'pending' AND dedupe_key IS NOT NULL
             DO UPDATE SET payload = EXCLUDED.payload",
            [$type, $dedupeKey, json_encode($payload, JSON_UNESCAPED_SLASHES), $delaySeconds],
        );
        // Wake a worker that is waiting with LISTEN.
        $this->db->run("SELECT pg_notify('alliancebb_jobs', ?)", [$type]);
    }

    /** Run due jobs. Returns how many ran. */
    public function runDue(int $limit = 20): int
    {
        $ran = 0;
        while ($ran < $limit) {
            $job = $this->db->transaction(fn (Database $db) => $db->one(
                "UPDATE jobs SET attempts = attempts + 1, run_at = now() + interval '5 minutes'
                  WHERE id = (SELECT id FROM jobs WHERE state = 'pending' AND run_at <= now()
                              ORDER BY run_at, id FOR UPDATE SKIP LOCKED LIMIT 1)
                  RETURNING *",
            ));
            if ($job === null) {
                break;
            }
            $ran++;
            $payload = json_decode((string) $job['payload'], true) ?: [];
            try {
                $handler = $this->handlers[$job['type']] ?? throw new \RuntimeException('No handler for job type ' . $job['type']);
                $handler($payload);
                $this->db->run('DELETE FROM jobs WHERE id = ?', [$job['id']]);
            } catch (PermanentFailure $e) {
                $this->db->run("UPDATE jobs SET state = 'failed', last_error = ? WHERE id = ?", [$e->getMessage(), $job['id']]);
            } catch (Throwable $e) {
                $attempts = (int) $job['attempts'];
                $delay = min(3600, 10 * (2 ** min($attempts, 9)));
                $state = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
                $this->db->run(
                    'UPDATE jobs SET state = ?, last_error = ?, run_at = now() + make_interval(secs => ?) WHERE id = ?',
                    [$state, mb_substr($e->getMessage(), 0, 1000), $delay, $job['id']],
                );
                error_log('AllianceBB job ' . $job['type'] . ' failed: ' . $e->getMessage());
            }
        }
        return $ran;
    }

    /** @return list<array<string, mixed>> */
    public function recentFailures(int $limit = 20): array
    {
        return $this->db->all("SELECT * FROM jobs WHERE state = 'failed' ORDER BY id DESC LIMIT ?", [$limit]);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->value("SELECT count(*) FROM jobs WHERE state = 'pending'");
    }
}
