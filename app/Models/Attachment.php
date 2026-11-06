<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 附件模型（需求 4.4 附件文件精细化管理）。
 */
class Attachment extends Model
{
    protected static $table = 'attachments';

    /**
     * 后台附件列表。
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginateByFilters(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where  = ['a.deleted_at IS NULL'];
        $params = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[]      = '(a.orig_name LIKE :kw OR k.title LIKE :kw)';
            $params['kw'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
        }

        $extension = trim((string) ($filters['ext'] ?? ''));
        if ($extension !== '') {
            $where[]       = 'a.ext = :ext';
            $params['ext'] = $extension;
        }

        $caseId = (int) ($filters['case_id'] ?? 0);
        if ($caseId > 0) {
            $where[]        = 'a.case_id = :case_id';
            $params['case_id'] = $caseId;
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) Database::scalar(
            'SELECT COUNT(*) FROM attachments a LEFT JOIN cases k ON k.id = a.case_id WHERE ' . $whereSql,
            $params
        );

        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT a.*, k.title AS case_title, k.status AS case_status, u.username AS uploader_name
                FROM attachments a
                LEFT JOIN cases k ON k.id = a.case_id
                LEFT JOIN users u ON u.id = a.uploader_id
                WHERE ' . $whereSql . '
                ORDER BY a.id DESC
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

        return [
            'items'     => Database::select($sql, $params),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * 某案例的附件列表。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function byCase(int $caseId): array
    {
        return Database::select(
            'SELECT * FROM attachments WHERE case_id = :id AND deleted_at IS NULL ORDER BY id ASC',
            ['id' => $caseId]
        );
    }

    /**
     * 某条员工反馈的附件列表（转案例前 case_id 为 0）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function byFeedback(int $feedbackId): array
    {
        return Database::select(
            'SELECT * FROM attachments WHERE feedback_id = :id AND deleted_at IS NULL ORDER BY id ASC',
            ['id' => $feedbackId]
        );
    }

    /**
     * 反馈转案例后，把附件一并挂到案例上（保留 feedback_id 便于溯源）。
     */
    public static function bindToCase(int $feedbackId, int $caseId): int
    {
        return Database::execute(
            'UPDATE attachments SET case_id = :cid WHERE feedback_id = :fid AND deleted_at IS NULL',
            ['cid' => $caseId, 'fid' => $feedbackId]
        );
    }

    /**
     * 批量取多个案例的附件，避免列表页 N+1 查询。
     *
     * @param array<int,int> $caseIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function groupByCases(array $caseIds): array
    {
        $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds))));
        if ($caseIds === []) {
            return [];
        }
        $placeholders = [];
        $params       = [];
        foreach ($caseIds as $index => $id) {
            $key                = 'id' . $index;
            $placeholders[]     = ':' . $key;
            $params[$key]       = $id;
        }

        $rows = Database::select(
            'SELECT * FROM attachments WHERE deleted_at IS NULL AND case_id IN (' . implode(',', $placeholders) . ')
             ORDER BY id ASC',
            $params
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['case_id']][] = $row;
        }
        return $grouped;
    }

    public static function softDelete(int $id): int
    {
        return Database::execute(
            'UPDATE attachments SET deleted_at = :t WHERE id = :id',
            ['t' => now(), 'id' => $id]
        );
    }

    /**
     * 批量取多条员工反馈的附件，避免台账列表页 N+1 查询。
     *
     * @param array<int,int> $feedbackIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function groupByFeedbacks(array $feedbackIds): array
    {
        $feedbackIds = array_values(array_unique(array_filter(array_map('intval', $feedbackIds))));
        if ($feedbackIds === []) {
            return [];
        }
        $placeholders = [];
        $params       = [];
        foreach ($feedbackIds as $index => $id) {
            $key                = 'fid' . $index;
            $placeholders[]     = ':' . $key;
            $params[$key]       = $id;
        }

        $rows = Database::select(
            'SELECT * FROM attachments WHERE deleted_at IS NULL AND feedback_id IN (' . implode(',', $placeholders) . ')
             ORDER BY id ASC',
            $params
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['feedback_id']][] = $row;
        }
        return $grouped;
    }

    public static function unbind(int $id): int
    {
        return Database::execute('UPDATE attachments SET case_id = 0 WHERE id = :id', ['id' => $id]);
    }

    public static function incrementDownload(int $id): void
    {
        Database::execute('UPDATE attachments SET download_count = download_count + 1 WHERE id = :id', ['id' => $id]);
    }

    /**
     * 磁盘占用统计。
     *
     * @return array{count:int,bytes:int}
     */
    public static function storageUsage(): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS total, COALESCE(SUM(size), 0) AS bytes FROM attachments WHERE deleted_at IS NULL'
        );
        return [
            'count' => (int) ($row['total'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
        ];
    }

    /**
     * 按扩展名统计。
     *
     * @return array<int,array{ext:string,count:int}>
     */
    public static function countByExtension(): array
    {
        $rows = Database::select(
            'SELECT ext, COUNT(*) AS total FROM attachments WHERE deleted_at IS NULL GROUP BY ext ORDER BY total DESC'
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['ext' => (string) $row['ext'], 'count' => (int) $row['total']];
        }
        return $result;
    }
}
