<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 模型基类：统一表名、主键与分页查询能力。
 */
abstract class Model
{
    /** @var string 数据表名（不含前缀） */
    protected static $table = '';

    /** @var string 主键字段 */
    protected static $primaryKey = 'id';

    public static function table(): string
    {
        return static::$table;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :id LIMIT 1', static::$table, static::$primaryKey),
            ['id' => $id]
        );
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $params
     */
    public static function count(string $where = '1 = 1', array $params = []): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', static::$table, $where);
        return (int) Database::scalar($sql, $params);
    }

    /**
     * 分页查询。
     *
     * @param array<string,mixed>|array<int,mixed> $params
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginate(
        string $where = '1 = 1',
        array $params = [],
        string $orderBy = 'id DESC',
        int $page = 1,
        int $perPage = 20
    ): array {
        $page    = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $total   = self::count($where, $params);
        $offset  = ($page - 1) * $perPage;

        $sql = sprintf(
            'SELECT * FROM `%s` WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
            static::$table,
            $where,
            $orderBy,
            $perPage,
            $offset
        );
        $items = Database::select($sql, $params);

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        return Database::insert(static::$table, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function modify(int $id, array $data): int
    {
        return Database::update(
            static::$table,
            $data,
            sprintf('`%s` = :pk', static::$primaryKey),
            ['pk' => $id]
        );
    }
}
