<?php

declare(strict_types=1);

namespace AllianceBB\Update;

use AllianceBB\App;
use AllianceBB\Core\Database;
use AllianceBB\Core\Migrator;
use AllianceBB\Plugin\PluginManager;
use InvalidArgumentException;
use PharData;
use RuntimeException;
use ZipArchive;

/**
 * Updates AllianceBB from the admin panel, from an uploaded .zip/.tar.gz or
 * the latest GitHub release.
 *
 * 1. Unpack the package into var/updates and check it is AllianceBB.
 * 2. Back up the database (pg_dump, when available) and the current code.
 * 3. Swap in the new code, run migrations and restart the workers.
 * Each update keeps its backup, with a Roll back button.
 *
 * Only core folders are replaced. Plugins, extra themes, var/ and .env stay.
 */
final class Updater
{
    public const REPO = 'wired420/AllianceBB';

    /** Paths that belong to the core and are replaced by an update. */
    private const CORE_PATHS = ['bin', 'docs', 'migrations', 'public', 'src', 'vendor', 'themes/default', 'plugins/signatures',
        'composer.json', 'composer.lock', 'LICENSE', 'NOTICE', 'README.md', 'TRADEMARKS.md'];

    private const MAX_PACKAGE = 100 * 1024 * 1024;

    public function __construct(private readonly App $app)
    {
    }

    private function dir(string $path): string
    {
        $dir = $this->app->rootDir . '/var/' . $path;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** @return array{tag: string, version: string, asset_url: ?string, sha256_url: ?string, notes: string, published_at: string}|null */
    public function latestRelease(): ?array
    {
        $json = $this->fetch('https://api.github.com/repos/' . self::REPO . '/releases/latest', 'application/vnd.github+json');
        $release = json_decode($json, true);
        if (!is_array($release) || empty($release['tag_name'])) {
            return null;
        }
        $asset = null;
        $sha = null;
        foreach ((array) ($release['assets'] ?? []) as $a) {
            $name = (string) ($a['name'] ?? '');
            if (preg_match('/^alliancebb-.*\.zip$/', $name)) {
                $asset = (string) $a['browser_download_url'];
            } elseif (preg_match('/^alliancebb-.*\.zip\.sha256$/', $name)) {
                $sha = (string) $a['browser_download_url'];
            }
        }
        return [
            'tag' => (string) $release['tag_name'],
            'version' => ltrim((string) $release['tag_name'], 'v'),
            'asset_url' => $asset,
            'sha256_url' => $sha,
            'notes' => mb_substr((string) ($release['body'] ?? ''), 0, 4000),
            'published_at' => (string) ($release['published_at'] ?? ''),
        ];
    }

    /** Download the latest GitHub release and install it. Returns the new version. */
    public function installLatest(): string
    {
        $release = $this->latestRelease() ?? throw new RuntimeException('No releases found on GitHub yet.');
        if ($release['asset_url'] === null) {
            throw new RuntimeException('The latest release has no alliancebb-*.zip package attached.');
        }
        $file = $this->dir('updates') . '/download-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($file, $this->fetch($release['asset_url'], 'application/octet-stream'));
        if ($release['sha256_url'] !== null) {
            $expected = strtolower(substr(trim($this->fetch($release['sha256_url'], 'text/plain')), 0, 64));
            if (!hash_equals($expected, hash_file('sha256', $file))) {
                @unlink($file);
                throw new RuntimeException('The download does not match its checksum. Nothing was changed.');
            }
        }
        try {
            return $this->install($file, 'download.zip');
        } finally {
            @unlink($file);
        }
    }

    /** Install from a package file. Returns the new version. */
    public function install(string $packagePath, string $originalName): string
    {
        if (filesize($packagePath) > self::MAX_PACKAGE) {
            throw new InvalidArgumentException('That package is too large.');
        }
        $staging = $this->dir('updates') . '/staging-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        mkdir($staging, 0775, true);
        try {
            $root = $this->extract($packagePath, $originalName, $staging);
            $version = $this->checkPackage($root);
            $backup = $this->backup();
            try {
                $this->swapIn($root);
                $this->migrate();
            } catch (\Throwable $e) {
                $this->restoreCode($backup);
                throw new RuntimeException('The update failed and the previous version was put back: ' . $e->getMessage(), 0, $e);
            }
            file_put_contents($backup . '/meta.json', (string) json_encode([
                'from' => App::VERSION, 'to' => $version, 'at' => date(DATE_ATOM),
            ]));
            $this->app->touchConfig();
            return $version;
        } finally {
            PluginManager::removeDir($staging);
        }
    }

    /** @return string folder holding the unpacked AllianceBB files */
    private function extract(string $packagePath, string $originalName, string $staging): string
    {
        $lower = strtolower($originalName);
        if (str_ends_with($lower, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($packagePath) !== true) {
                throw new InvalidArgumentException('That file is not a valid zip.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    $zip->close();
                    throw new InvalidArgumentException('The package contains unsafe paths.');
                }
            }
            $zip->extractTo($staging);
            $zip->close();
        } elseif (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
            $tar = $staging . '/package.tar.gz';
            copy($packagePath, $tar);
            $phar = new PharData($tar);
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                $rel = substr((string) $file->getPathname(), strlen('phar://' . $tar) + 1);
                if (str_contains($rel, '..')) {
                    throw new InvalidArgumentException('The package contains unsafe paths.');
                }
            }
            $phar->extractTo($staging, null, true);
            unlink($tar);
        } else {
            throw new InvalidArgumentException('Upload a .zip or .tar.gz package.');
        }
        // The package may hold the files at its root or inside one folder.
        if (is_file($staging . '/src/App.php')) {
            return $staging;
        }
        $dirs = glob($staging . '/*', GLOB_ONLYDIR) ?: [];
        if (count($dirs) === 1 && is_file($dirs[0] . '/src/App.php')) {
            return $dirs[0];
        }
        throw new InvalidArgumentException('That package does not look like AllianceBB (no src/App.php).');
    }

    private function checkPackage(string $root): string
    {
        if (!is_file($root . '/vendor/autoload.php')) {
            throw new InvalidArgumentException('The package has no vendor folder. Use a release package, not the GitHub source download.');
        }
        $source = (string) file_get_contents($root . '/src/App.php');
        if (!preg_match("/const VERSION = '([0-9A-Za-z.+-]+)'/", $source, $m)) {
            throw new InvalidArgumentException('Could not read the version of that package.');
        }
        return $m[1];
    }

    /** @return string backup folder */
    private function backup(): string
    {
        $dir = $this->dir('backups') . '/' . date('Ymd-His') . '-' . App::VERSION;
        mkdir($dir . '/code', 0775, true);
        foreach (self::CORE_PATHS as $path) {
            $from = $this->app->rootDir . '/' . $path;
            if (file_exists($from)) {
                self::copyPath($from, $dir . '/code/' . $path);
            }
        }
        $this->dumpDatabase($dir . '/database.sql');
        return $dir;
    }

    private function dumpDatabase(string $file): void
    {
        $url = getenv('DATABASE_URL') ?: '';
        $pgDump = trim((string) shell_exec('command -v pg_dump 2>/dev/null'));
        if ($url === '' || $pgDump === '' || !function_exists('proc_open')) {
            file_put_contents($file . '.skipped', "pg_dump not available; database not backed up.\n");
            return;
        }
        $proc = proc_open([$pgDump, '--no-owner', '--clean', '--if-exists', '--file=' . $file, $url], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start pg_dump.');
        }
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new RuntimeException('Database backup failed, so nothing was changed: ' . trim((string) $err));
        }
    }

    private function swapIn(string $root): void
    {
        foreach (self::CORE_PATHS as $path) {
            $new = $root . '/' . $path;
            $current = $this->app->rootDir . '/' . $path;
            if (!file_exists($new)) {
                continue;
            }
            $old = $current . '.old-' . bin2hex(random_bytes(3));
            if (file_exists($current)) {
                rename($current, $old);
            }
            if (!is_dir(dirname($current))) {
                mkdir(dirname($current), 0775, true);
            }
            if (!@rename($new, $current)) {
                self::copyPath($new, $current);
            }
            if (file_exists($old)) {
                is_dir($old) ? PluginManager::removeDir($old) : unlink($old);
            }
        }
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    private function migrate(): void
    {
        $db = $this->app->get(Database::class);
        (new Migrator($db))->migrate($this->app->rootDir . '/migrations');
        foreach ($this->app->get(PluginManager::class)->all() as $name => $plugin) {
            $dir = $this->app->rootDir . "/plugins/$name/migrations";
            if ($plugin['enabled'] && is_dir($dir)) {
                (new Migrator($db))->migrate($dir, "plugin:$name");
            }
        }
    }

    private function restoreCode(string $backup): void
    {
        foreach (self::CORE_PATHS as $path) {
            $saved = $backup . '/code/' . $path;
            $current = $this->app->rootDir . '/' . $path;
            if (!file_exists($saved)) {
                continue;
            }
            if (file_exists($current)) {
                is_dir($current) ? PluginManager::removeDir($current) : unlink($current);
            }
            self::copyPath($saved, $current);
        }
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /** @return list<array{id: string, from: string, to: string, at: string, has_database: bool}> newest first */
    public function backups(): array
    {
        $out = [];
        foreach (glob($this->dir('backups') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $meta = json_decode((string) @file_get_contents($dir . '/meta.json'), true);
            if (!is_array($meta)) {
                continue; // an update that did not finish
            }
            $out[] = [
                'id' => basename($dir), 'from' => (string) $meta['from'], 'to' => (string) $meta['to'],
                'at' => (string) $meta['at'], 'has_database' => is_file($dir . '/database.sql'),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['id'], $a['id']));
        return $out;
    }

    /** Put back the code (and database, when it was backed up) from before an update. */
    public function rollback(string $id): string
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9A-Za-z.+-]+$/', $id) || !is_dir($dir = $this->dir('backups') . '/' . $id)) {
            throw new InvalidArgumentException('That backup does not exist.');
        }
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        if (is_file($dir . '/database.sql')) {
            $psql = trim((string) shell_exec('command -v psql 2>/dev/null'));
            if ($psql === '' || !function_exists('proc_open')) {
                throw new RuntimeException('psql is not available, so the database cannot be restored.');
            }
            $proc = proc_open([$psql, '--quiet', '--single-transaction', '--file=' . $dir . '/database.sql', (string) getenv('DATABASE_URL')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($proc) !== 0) {
                throw new RuntimeException('Restoring the database failed: ' . trim((string) $err));
            }
        }
        $this->restoreCode($dir);
        $this->app->touchConfig();
        return (string) ($meta['from'] ?? 'previous version');
    }

    private function fetch(string $url, string $accept): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'User-Agent: AllianceBB/' . App::VERSION],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            throw new RuntimeException("Could not download $url (" . ($error ?: "HTTP $status") . ')');
        }
        return (string) $body;
    }

    private static function copyPath(string $from, string $to): void
    {
        if (is_file($from)) {
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0775, true);
            }
            copy($from, $to);
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        if (!is_dir($to)) {
            mkdir($to, 0775, true);
        }
        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? (is_dir($target) || mkdir($target, 0775, true)) : copy($item->getPathname(), $target);
        }
    }
}
