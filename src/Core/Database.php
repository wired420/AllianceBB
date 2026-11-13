<?php

declare(strict_types=1);

namespace AllianceBB\Core;

use PDO;
use PDOStatement;
use Throwable;

/** Thin PDO wrapper. All SQL goes through here so another driver could be added later. */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $url)
    {
    }

    public static function fromEnv(): self
    {
        $url = getenv('DATABASE_URL') ?: 'postgres://alliancebb:alliancebb@127.0.0.1:5432/alliancebb';
        return new self($url);
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $parts = parse_url($this->url);
            if ($parts === false || !isset($parts['host'])) {
                throw new \RuntimeException('DATABASE_URL is not a valid postgres:// URL');
            }
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $parts['host'],
                $parts['port'] ?? 5432,
                ltrim($parts['path'] ?? '/alliancebb', '/'),
            );
            $this->pdo = new PDO($dsn, urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]);
        }
        return $this->pdo;
    }

    /** Drop the connection, e.g. after an error left it in a bad state. */
    public function reset(): void
    {
        $this->pdo = null;
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @template T
     * @param callable(Database): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $work($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $work($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
