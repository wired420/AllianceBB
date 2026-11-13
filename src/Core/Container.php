<?php

declare(strict_types=1);

namespace AllianceBB\Core;

use RuntimeException;

/**
 * Service container. Core registers every service here; plugins can replace
 * any of them with override() before it is first used.
 */
final class Container
{
    /** @var array<string, callable(Container): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /** Replace a service. The previous factory is passed in so it can be wrapped. */
    public function override(string $id, callable $factory): void
    {
        $previous = $this->factories[$id] ?? null;
        $this->set($id, fn (Container $c) => $factory($c, $previous));
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @template T
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Unknown service: $id");
        }
        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
