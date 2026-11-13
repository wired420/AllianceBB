<?php

declare(strict_types=1);

namespace AllianceBB\View;

/**
 * Themes are folders under /themes with a theme.json, Twig templates and assets.
 * A theme may name a parent and override only some templates.
 * theme.json lists editable colors; the admin panel stores overrides.
 */
final class Themes
{
    public function __construct(private readonly string $themesDir)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $themes = [];
        foreach (glob($this->themesDir . '/*/theme.json') ?: [] as $file) {
            $name = basename(dirname($file));
            $meta = json_decode((string) file_get_contents($file), true);
            if (is_array($meta) && self::validName($name)) {
                $themes[$name] = $meta + ['name' => $name, 'title' => $name, 'colors' => []];
            }
        }
        ksort($themes);
        return $themes;
    }

    /** @return array<string, mixed>|null */
    public function get(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** @return list<string> template folders, most specific first */
    public function templateChain(string $name): array
    {
        $chain = [];
        $seen = [];
        $current = $this->get($name) !== null ? $name : 'default';
        while ($current !== null && !isset($seen[$current])) {
            $seen[$current] = true;
            $dir = $this->themesDir . '/' . $current . '/templates';
            if (is_dir($dir)) {
                $chain[] = $dir;
            }
            $parent = $this->get($current)['parent'] ?? null;
            $current = is_string($parent) ? $parent : null;
        }
        if (!isset($seen['default']) && is_dir($this->themesDir . '/default/templates')) {
            $chain[] = $this->themesDir . '/default/templates';
        }
        return $chain;
    }

    /**
     * Theme color defaults with the admin's overrides applied.
     * @param array<string, mixed> $overrides theme.colors setting: [theme => [key => color]]
     * @return array<string, array{label: string, value: string}>
     */
    public function colors(string $name, array $overrides): array
    {
        $out = [];
        $chain = [];
        $current = $name;
        while ($current !== null && ($meta = $this->get($current)) !== null && !in_array($current, $chain, true)) {
            $chain[] = $current;
            $current = is_string($meta['parent'] ?? null) ? $meta['parent'] : null;
        }
        foreach (array_reverse($chain) as $theme) {
            foreach ((array) ($this->get($theme)['colors'] ?? []) as $key => $def) {
                $out[$key] = ['label' => (string) ($def['label'] ?? $key), 'value' => (string) ($def['value'] ?? '#000000')];
            }
        }
        foreach ((array) ($overrides[$name] ?? []) as $key => $value) {
            if (isset($out[$key]) && self::validColor((string) $value)) {
                $out[$key]['value'] = (string) $value;
            }
        }
        return $out;
    }

    public function cssVariables(string $name, array $overrides): string
    {
        $lines = [];
        foreach ($this->colors($name, $overrides) as $key => $color) {
            if (self::validName($key) && self::validColor($color['value'])) {
                $lines[] = "--abb-$key: {$color['value']};";
            }
        }
        return '<style>:root{' . implode('', $lines) . '}</style>';
    }

    public function assetPath(string $theme, string $path): ?string
    {
        if (!self::validName($theme) || str_contains($path, '..') || !preg_match('#^[A-Za-z0-9_./-]+$#', $path)) {
            return null;
        }
        foreach ([$theme, $this->get($theme)['parent'] ?? null, 'default'] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $file = $this->themesDir . '/' . $candidate . '/assets/' . $path;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name);
    }

    public static function validColor(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{3,8}$/', $value);
    }
}
