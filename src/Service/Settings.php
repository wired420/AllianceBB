<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Database;

/**
 * Site settings stored as JSON values. Cached per worker; the cache is
 * dropped whenever the config stamp changes (see App::configChanged()).
 */
final class Settings
{
    public const DEFAULTS = [
        'site.name' => 'AllianceBB',
        'site.description' => 'A forum that shares sections with other sites.',
        'site.registration_open' => true,
        'site.url' => '',
        'federation.mode' => 'private', // public | private | off
        'api.enabled' => true,
        'theme.name' => 'default',
        'theme.colors' => [],
        'installed' => false,
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : ($default ?? self::DEFAULTS[$key] ?? null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->db->run(
            'INSERT INTO settings (key, value) VALUES (?, ?::jsonb)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
            [$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
        $this->cache = null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $rows = $this->db->all('SELECT key, value FROM settings');
            } catch (\PDOException) {
                return self::DEFAULTS; // not installed yet
            }
            $this->cache = self::DEFAULTS;
            foreach ($rows as $row) {
                $this->cache[$row['key']] = json_decode((string) $row['value'], true);
            }
        }
        return $this->cache;
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }

    public function isInstalled(): bool
    {
        try {
            return (bool) $this->get('installed');
        } catch (\PDOException) {
            return false; // tables not created yet
        }
    }
}
