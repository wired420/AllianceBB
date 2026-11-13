<?php

declare(strict_types=1);

namespace AllianceBB\Plugin;

use AllianceBB\App;
use AllianceBB\Core\Database;
use AllianceBB\Core\Migrator;
use InvalidArgumentException;
use Throwable;
use ZipArchive;

/**
 * Plugins are folders under /plugins with a plugin.json manifest and a PHP
 * entry file that returns a function taking the App. Through the App a
 * plugin can add hooks, routes, templates and services, or replace any
 * core service, so it can change almost anything.
 */
final class PluginManager
{
    /** @var array<string, string> errors from plugins that failed to load */
    private array $errors = [];

    public function __construct(
        private readonly string $pluginsDir,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, array<string, mixed>> every plugin on disk with its enabled state */
    public function all(): array
    {
        $enabled = [];
        try {
            foreach ($this->db->all('SELECT name, enabled FROM plugins') as $row) {
                $enabled[$row['name']] = (bool) $row['enabled'];
            }
        } catch (\PDOException) {
            // not installed yet
        }
        $plugins = [];
        foreach (glob($this->pluginsDir . '/*/plugin.json') ?: [] as $file) {
            $name = basename(dirname($file));
            $meta = $this->readManifest($file);
            if ($meta === null || !self::validName($name)) {
                continue;
            }
            $plugins[$name] = $meta + [
                'name' => $name,
                'enabled' => $enabled[$name] ?? false,
                'error' => $this->errors[$name] ?? null,
            ];
        }
        ksort($plugins);
        return $plugins;
    }

    public function loadEnabled(App $app): void
    {
        foreach ($this->all() as $name => $plugin) {
            if (!$plugin['enabled']) {
                continue;
            }
            $dir = $this->pluginsDir . '/' . $name;
            try {
                $entry = $dir . '/' . ($plugin['main'] ?? 'plugin.php');
                $boot = require $entry;
                if (!is_callable($boot)) {
                    throw new \RuntimeException('plugin.php must return a function');
                }
                if (is_dir($dir . '/templates')) {
                    $app->view()->addNamespace($name, $dir . '/templates');
                }
                $boot($app, $plugin);
            } catch (Throwable $e) {
                $this->errors[$name] = $e->getMessage();
                error_log("AllianceBB: plugin $name failed to load: " . $e->getMessage());
            }
        }
    }

    public function enable(string $name): void
    {
        $plugin = $this->all()[$name] ?? throw new InvalidArgumentException('Plugin not found.');
        $migrations = $this->pluginsDir . '/' . $name . '/migrations';
        if (is_dir($migrations)) {
            (new Migrator($this->db))->migrate($migrations, 'plugin:' . $name);
        }
        $this->db->run(
            'INSERT INTO plugins (name, enabled, version) VALUES (?, true, ?)
             ON CONFLICT (name) DO UPDATE SET enabled = true, version = EXCLUDED.version',
            [$name, (string) ($plugin['version'] ?? '')],
        );
    }

    public function disable(string $name): void
    {
        $this->db->run('UPDATE plugins SET enabled = false WHERE name = ?', [$name]);
    }

    /** Install a plugin from an uploaded zip. Returns the plugin's name. */
    public function installZip(string $zipPath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('That file is not a valid zip.');
        }
        try {
            // The zip holds either plugin.json at its root or one folder containing it.
            $prefix = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_contains($entry, '\\')) {
                    throw new InvalidArgumentException('The zip contains unsafe paths.');
                }
                if (preg_match('#^([^/]+/)?plugin\.json$#', $entry, $m)) {
                    $prefix = $m[1] ?? '';
                }
            }
            if ($prefix === null) {
                throw new InvalidArgumentException('No plugin.json found in the zip.');
            }
            $meta = json_decode((string) $zip->getFromName($prefix . 'plugin.json'), true);
            $name = is_array($meta) ? (string) ($meta['name'] ?? '') : '';
            if (!self::validName($name)) {
                throw new InvalidArgumentException('plugin.json needs a "name" of lowercase letters, numbers and dashes.');
            }
            $target = $this->pluginsDir . '/' . $name;
            $staging = $this->pluginsDir . '/.staging-' . bin2hex(random_bytes(4));
            mkdir($staging, 0775, true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if ($prefix !== '' && !str_starts_with($entry, $prefix)) {
                    continue;
                }
                $relative = substr($entry, strlen($prefix));
                if ($relative === '' || str_ends_with($relative, '/')) {
                    continue;
                }
                $dest = $staging . '/' . $relative;
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0775, true);
                }
                file_put_contents($dest, (string) $zip->getFromIndex($i));
            }
            if (is_dir($target)) {
                self::removeDir($target);
            }
            rename($staging, $target);
            return $name;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $file): ?array
    {
        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) ? $meta : null;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $name);
    }

    public static function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
