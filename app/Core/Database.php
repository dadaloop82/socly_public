<?php

declare(strict_types=1);

namespace Socly\Core;

use PDO;

final class Database
{
    private ?PDO $pdo = null;
    private static ?bool $reachableCache = null;

    public function __construct(
        private readonly array $config
    ) {
    }

    /**
     * True when the failure is about reaching/authenticating to MySQL
     * (misconfigured .env), not a later SQL error on a live connection.
     */
    public static function isConnectionException(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $msg = $e->getMessage();
        $sqlState = is_string($e->errorInfo[0] ?? null) ? (string) $e->errorInfo[0] : '';
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // Common MySQL/MariaDB connect failures
        if (in_array($driverCode, [1044, 1045, 1049, 2002, 2003, 2006, 2013], true)) {
            return true;
        }
        if ($sqlState === 'HY000' || $sqlState === '28000' || $sqlState === '08006') {
            return true;
        }

        return (bool) preg_match(
            '/Access denied|Unknown database|Connection refused|Can\'t connect|could not find driver|No such file or directory|server has gone away|Connection timed out/i',
            $msg
        );
    }

    /** Lightweight connect probe (cached per request). */
    public function isReachable(): bool
    {
        if (self::$reachableCache !== null) {
            return self::$reachableCache;
        }
        try {
            $this->pdo()->query('SELECT 1');
            self::$reachableCache = true;
        } catch (\Throwable $e) {
            // Only connection/auth failures count as unreachable.
            self::$reachableCache = !self::isConnectionException($e);
        }
        return self::$reachableCache;
    }

    /** @internal Reset probe cache (tests / after writing .env). */
    public static function clearReachableCache(): void
    {
        self::$reachableCache = null;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->config['host'],
                $this->config['port'],
                $this->config['database']
            );
            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$reachableCache = true;
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn ($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $cols),
            implode(', ', $placeholders)
        );
        $this->query($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): void
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = "`{$col}` = :{$col}";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        $this->query($sql, $data + $whereParams);
    }

    public function beginTransaction(): void
    {
        $this->pdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo()->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }
}
