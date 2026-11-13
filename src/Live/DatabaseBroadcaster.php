<?php

declare(strict_types=1);

namespace AllianceBB\Live;

use AllianceBB\Core\Database;

/**
 * Fallback for installs without a Mercure hub: events go into a table and
 * pages poll /live/poll every few seconds.
 */
final class DatabaseBroadcaster implements Broadcaster
{
    public function __construct(private readonly Database $db)
    {
    }

    public function publish(string $channel, string $type, array $data): void
    {
        $this->db->run(
            'INSERT INTO live_events (channel, type, data) VALUES (?, ?, ?::jsonb)',
            [$channel, $type, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
        if (random_int(1, 200) === 1) {
            $this->db->run("DELETE FROM live_events WHERE created_at < now() - interval '1 day'");
        }
    }

    /** @param list<string> $channels @return list<array<string, mixed>> */
    public function since(array $channels, int $afterId): array
    {
        if ($channels === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($channels), '?'));
        $rows = $this->db->all(
            "SELECT id, channel, type, data FROM live_events WHERE id > ? AND channel IN ($placeholders) ORDER BY id LIMIT 100",
            [$afterId, ...$channels],
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['data'] = json_decode((string) $row['data'], true);
        }
        return $rows;
    }

    public function latestId(): int
    {
        try {
            return (int) $this->db->value('SELECT COALESCE(MAX(id), 0) FROM live_events');
        } catch (\PDOException) {
            return 0; // not installed yet
        }
    }

    public function clientConfig(): array
    {
        return ['mode' => 'poll', 'after' => $this->latestId()];
    }
}
