<?php

declare(strict_types=1);

namespace AllianceBB\Core;

/**
 * Applies numbered .sql files from the core migrations folder and from each
 * enabled plugin. Each file runs in its own transaction; PostgreSQL rolls back
 * schema changes too, so a failed migration leaves the database untouched.
 */
final class Migrator
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<string> names of migrations applied */
    public function migrate(string $directory, string $scope = 'core'): array
    {
        $this->db->run('CREATE TABLE IF NOT EXISTS schema_migrations (
            scope text NOT NULL, name text NOT NULL, applied_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (scope, name))');

        $done = array_column(
            $this->db->all('SELECT name FROM schema_migrations WHERE scope = ?', [$scope]),
            'name',
        );
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $this->db->transaction(function (Database $db) use ($sql, $scope, $name): void {
                $db->pdo()->exec($sql);
                $db->run('INSERT INTO schema_migrations (scope, name) VALUES (?, ?)', [$scope, $name]);
            });
            $applied[] = $name;
        }
        return $applied;
    }
}
