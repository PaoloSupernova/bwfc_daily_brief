<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database connection wrapper.
 *
 * Single shared PDO instance. Used throughout the app via Database::connection().
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $config = require BASE_PATH . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['dbname'],
            $config['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $config['user'], $config['pass'], $config['options']);
        } catch (PDOException $e) {
            if (env('APP_DEBUG', false) === true) {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }
            throw new RuntimeException('Database unavailable. Check XAMPP MySQL is running.');
        }

        return self::$pdo;
    }

    /**
     * Run a prepared SELECT and return all rows.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Run a prepared SELECT and return the first row (or null).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Run a prepared INSERT/UPDATE/DELETE. Returns affected rows.
     *
     * @param array<string, mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Run an INSERT and return the last insert ID.
     *
     * @param array<string, mixed> $params
     */
    public static function insert(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return (int)self::connection()->lastInsertId();
    }

    /**
     * Build and run an INSERT from a column => value map. Returns last insert ID.
     *
     * @param array<string, mixed> $data
     */
    public static function insertRow(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($data);
        return (int)self::connection()->lastInsertId();
    }

    /**
     * Build and run an UPDATE from column => value map with simple AND-joined WHERE conditions.
     * Returns number of affected rows.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public static function updateRow(string $table, array $data, array $where): int
    {
        $setClauses = array_map(fn($c) => '`' . $c . '` = :set_' . $c, array_keys($data));
        $whereClauses = array_map(fn($c) => '`' . $c . '` = :whr_' . $c, array_keys($where));
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $setClauses) . ' WHERE ' . implode(' AND ', $whereClauses);
        $params = [];
        foreach ($data as $col => $val) {
            $params['set_' . $col] = $val;
        }
        foreach ($where as $col => $val) {
            $params['whr_' . $col] = $val;
        }
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        self::connection()->rollBack();
    }
}
