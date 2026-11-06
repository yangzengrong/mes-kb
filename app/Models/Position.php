<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 岗位分类模型（岗位操作手册维度：手册按责任岗位归档维护）。
 * 后台可维护、启停、排序，支持后续新增岗位。
 */
class Position extends Model
{
    protected static $table = 'positions';

    /**
     * 全部岗位（后台管理用）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return Database::select('SELECT * FROM positions ORDER BY sort ASC, id ASC');
    }

    /**
     * 仅启用岗位（前台 / 表单使用）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function enabled(): array
    {
        return Database::select('SELECT * FROM positions WHERE status = 1 ORDER BY sort ASC, id ASC');
    }

    /**
     * 下拉选项：id => 名称。
     *
     * @param bool $onlyEnabled
     * @return array<int,string>
     */
    public static function options(bool $onlyEnabled = false): array
    {
        $rows   = $onlyEnabled ? self::enabled() : self::all();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (string) $row['name'];
        }
        return $result;
    }

    /**
     * 岗位及其手册数量（岗位管理页 + 前台岗位手册分区共用）。
     * 仅统计 doc_type = manual 的文档。
     *
     * @param bool $onlyEnabled
     * @return array<int,array<string,mixed>>
     */
    public static function withManualCount(bool $onlyEnabled = false): array
    {
        $docFilter = "AND k.doc_type = 'manual'";
        $where     = $onlyEnabled ? 'WHERE p.status = 1' : '';
        return Database::select(
            "SELECT p.*, (
                SELECT COUNT(*) FROM cases k
                WHERE k.position_id = p.id AND k.deleted_at IS NULL AND k.status = 'published' {$docFilter}
             ) AS published_count, (
                SELECT COUNT(*) FROM cases k
                WHERE k.position_id = p.id AND k.deleted_at IS NULL {$docFilter}
             ) AS manual_count
             FROM positions p {$where}
             ORDER BY p.sort ASC, p.id ASC"
        );
    }

    /**
     * 该岗位下文档数（删除前占用校验）。
     */
    public static function caseCount(int $id): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND position_id = :pid',
            ['pid' => $id]
        );
    }

    /**
     * 系统操作手册总数（前台首页「系统操作手册」入口卡使用）。
     *
     * @param bool $publishedOnly 仅统计已发布
     */
    public static function manualTotal(bool $publishedOnly = true): int
    {
        $statusFilter = $publishedOnly ? "AND status = 'published'" : '';
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM cases
             WHERE doc_type = 'manual' AND deleted_at IS NULL {$statusFilter}"
        );
    }

    /**
     * 系统操作手册覆盖的岗位数（distinct position_id，0 视为通用不计入）。
     */
    public static function manualPositionCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(DISTINCT position_id) FROM cases
             WHERE doc_type = 'manual' AND deleted_at IS NULL AND status = 'published' AND position_id > 0"
        );
    }
}
