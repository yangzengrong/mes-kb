<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * 数据库访问层（PDO + 预处理）。
 * 全局单例，所有 SQL 一律走参数绑定，杜绝拼接注入。
 */
class Database
{
    /** @var PDO|null */
    private static $pdo = null;

    /** @var int 当前请求内执行的 SQL 条数（调试用） */
    private static $queryCount = 0;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('database.host', '127.0.0.1');
        $port    = (int) Config::get('database.port', 3306);
        $name    = (string) Config::get('database.name', '');
        $user    = (string) Config::get('database.user', '');
        $pass    = (string) Config::get('database.password', '');
        $charset = (string) Config::get('database.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET NAMES '{$charset}'");
            self::$pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败：' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }

    /**
     * 执行写入语句，返回受影响行数。
     *
     * @param array<string,mixed>|array<int,mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        self::$queryCount++;
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 取多行。
     *
     * @param array<string,mixed>|array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        self::$queryCount++;
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 取单行。
     *
     * @param array<string,mixed>|array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        self::$queryCount++;
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * 取单个标量值。
     *
     * @param array<string,mixed>|array<int,mixed> $params
     * @return mixed
     */
    public static function scalar(string $sql, array $params = [])
    {
        self::$queryCount++;
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * 插入并返回自增主键。
     *
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static function (string $col): string {
            return ':' . $col;
        }, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        self::execute($sql, $data);
        return (int) self::connection()->lastInsertId();
    }

    /**
     * 按主键更新。
     *
     * @param array<string,mixed> $data
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = sprintf('`%s` = :set_%s', $col, $col);
        }
        $params = [];
        foreach ($data as $key => $value) {
            $params['set_' . $key] = $value;
        }
        foreach ($whereParams as $key => $value) {
            $params[$key] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::execute($sql, $params);
    }

    public static function transaction(callable $callback)
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function tableExists(string $table): bool
    {
        try {
            $result = self::scalar('SHOW TABLES LIKE :t', ['t' => $table]);
            return $result !== null;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function queryCount(): int
    {
        return self::$queryCount;
    }
}
