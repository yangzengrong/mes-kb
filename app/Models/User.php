<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 用户模型（需求 4.1 用户权限管理）。
 */
class User extends Model
{
    protected static $table = 'users';

    /**
     * 后台用户列表。
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginateByFilters(array $filters, int $page = 1, int $perPage = 15): array
    {
        $where  = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 同名占位符在 PDO 原生预处理下会 HY093，两个字段各用唯一名。
            $where[] = '(username LIKE :kw_user OR real_name LIKE :kw_real)';
            $like    = '%' . $keyword . '%';
            $params['kw_user'] = $like;
            $params['kw_real'] = $like;
        }

        $role = trim((string) ($filters['role'] ?? ''));
        if ($role !== '' && array_key_exists($role, role_map())) {
            $where[]         = 'role = :role';
            $params['role']  = $role;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === '0' || $status === '1') {
            $where[]           = 'status = :status';
            $params['status']  = (int) $status;
        }

        return self::paginate(implode(' AND ', $where), $params, 'id ASC', $page, $perPage);
    }

    public static function existsUsername(string $username, int $excludeId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM users WHERE username = :u AND id <> :id';
        return (int) Database::scalar($sql, ['u' => $username, 'id' => $excludeId]) > 0;
    }

    /**
     * @return array<string,int>
     */
    public static function roleCounts(): array
    {
        $rows   = Database::select('SELECT role, COUNT(*) AS total FROM users GROUP BY role');
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['role']] = (int) $row['total'];
        }
        return $result;
    }
}
