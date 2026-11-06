<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 工序分类模型（线缆专属改版：一级分类 = 生产工序）。
 * 后台可维护、启停、排序，支持后续新增工序。
 */
class Process extends Model
{
    protected static $table = 'processes';

    /**
     * 全部工序（后台管理用）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return Database::select('SELECT * FROM processes ORDER BY sort ASC, id ASC');
    }

    /**
     * 仅启用工序（前台使用）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function enabled(): array
    {
        return Database::select('SELECT * FROM processes WHERE status = 1 ORDER BY sort ASC, id ASC');
    }

    /**
     * 下拉选项：id => 名称。
     *
     * @param bool $onlyEnabled
     * @return array<int,string>
     */
    public static function options(bool $onlyEnabled = false): array
    {
        $rows  = $onlyEnabled ? self::enabled() : self::all();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (string) $row['name'];
        }
        return $result;
    }

    /**
     * 工序及其文档数量（后台管理页 + 前台工序卡片 + 看板统计共用）。
     *
     * @param bool $onlyEnabled
     * @return array<int,array<string,mixed>>
     */
    public static function withCaseCount(bool $onlyEnabled = false): array
    {
        $where = $onlyEnabled ? 'WHERE p.status = 1' : '';
        $rows  = Database::select(
            "SELECT p.*, (
                SELECT COUNT(*) FROM cases k
                WHERE k.process_id = p.id AND k.deleted_at IS NULL AND k.status = 'published'
             ) AS published_count, (
                SELECT COUNT(*) FROM cases k
                WHERE k.process_id = p.id AND k.deleted_at IS NULL
             ) AS case_count
             FROM processes p {$where}
             ORDER BY p.sort ASC, p.id ASC"
        );
        return $rows;
    }

    /**
     * 工序 → 已发布文档数量（按文档类型统计，默认只算故障案例）。
     *
     * 与 withCaseCount() 的区别：withCaseCount 的 published_count 不区分 doc_type，
     * 会把操作手册、版本更新也算进工序篇数；本方法用于「工序按钮上的篇数」，
     * 必须与下方列表（doc_types=['case']）口径一致，否则按钮写 10 篇、列表只出 6 条。
     *
     * @param string $docType    case / manual / changelog
     * @param bool   $onlyEnabled 只统计启用工序
     * @return array<int,int> 工序 id => 篇数
     */
    public static function publishedDocCounts(string $docType = 'case', bool $onlyEnabled = true): array
    {
        $where = $onlyEnabled ? ' AND p.status = 1' : '';
        $rows  = Database::select(
            "SELECT k.process_id AS pid, COUNT(*) AS total
             FROM cases k
             INNER JOIN processes p ON p.id = k.process_id
             WHERE k.deleted_at IS NULL AND k.status = 'published'
               AND k.doc_type = :dtype{$where}
             GROUP BY k.process_id",
            ['dtype' => $docType]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['pid']] = (int) $row['total'];
        }
        return $result;
    }

    /**
     * 工序 → 故障类型分布（工序导航页用；只统计已发布的故障案例）。
     *
     * 一次聚合查询拿全部工序的分布，避免每个工序单独查一次。
     * 只统计 doc_type='case'：手册与版本更新不参与工序故障统计。
     *
     * @param bool $onlyEnabled 只统计启用工序
     * @return array<int,array<int,array{id:int,name:string,count:int}>>
     */
    public static function categoryStats(bool $onlyEnabled = true): array
    {
        $where = $onlyEnabled ? ' AND p.status = 1' : '';
        $rows  = Database::select(
            "SELECT p.id AS process_id, COALESCE(cat.id, 0) AS cat_id,
                    COALESCE(cat.name, '未分类') AS cat_name, COUNT(*) AS total
             FROM cases k
             INNER JOIN processes p ON p.id = k.process_id
             LEFT JOIN categories cat ON cat.id = k.category_id
             WHERE k.deleted_at IS NULL AND k.status = 'published' AND k.doc_type = 'case'{$where}
             GROUP BY p.id, cat.id, cat.name
             ORDER BY p.id ASC, total DESC"
        );
        $result = [];
        foreach ($rows as $row) {
            $pid = (int) $row['process_id'];
            $result[$pid][] = [
                'id'    => (int) $row['cat_id'],
                'name'  => (string) $row['cat_name'],
                'count' => (int) $row['total'],
            ];
        }
        return $result;
    }

    /**
     * 该工序下的案例数（删除前占用校验）。
     */
    public static function caseCount(int $id): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND process_id = :pid',
            ['pid' => $id]
        );
    }
}
