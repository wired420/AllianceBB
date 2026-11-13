<?php

declare(strict_types=1);

namespace AllianceBB\Core;

/**
 * Actions and filters. Core code calls these at every step so plugins can
 * observe events (actions) or change values on their way through (filters).
 */
final class Hooks
{
    /** @var array<string, array<int, list<callable>>> */
    private array $actions = [];

    /** @var array<string, array<int, list<callable>>> */
    private array $filters = [];

    public function addAction(string $name, callable $callback, int $priority = 10): void
    {
        $this->actions[$name][$priority][] = $callback;
        ksort($this->actions[$name]);
    }

    public function doAction(string $name, mixed ...$args): void
    {
        foreach ($this->actions[$name] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }

    public function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        $this->filters[$name][$priority][] = $callback;
        ksort($this->filters[$name]);
    }

    public function applyFilters(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->filters[$name] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }
        return $value;
    }

    public function hasFilter(string $name): bool
    {
        return !empty($this->filters[$name]);
    }
}
