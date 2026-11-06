<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 日志模型（需求 4.6 系统日志记录模块）。
 */
class LogModel extends Model
{
    protected static $table = 'operation_logs';

    /**
     * 操作日志分页。
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginateOperations(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where  = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 同名占位符在 PDO 原生预处理下会 HY093，四个字段各用唯一名。
            $where[] = '(username LIKE :kw_user OR detail LIKE :kw_detail OR action LIKE :kw_action OR ip LIKE :kw_ip)';
            $like    = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
            $params['kw_user']   = $like;
            $params['kw_detail'] = $like;
            $params['kw_action'] = $like;
            $params['kw_ip']     = $like;
        }

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $where[]        = 'action LIKE :action';
            $params['action'] = $action . '%';
        }

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $where[]         = 'user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $range = self::dateRange($filters);
        if ($range !== null) {
            $where[]       = 'created_at BETWEEN :start AND :end';
            $params['start'] = $range[0];
            $params['end']   = $range[1];
        }

        return self::paginate(implode(' AND ', $where), $params, 'id DESC', $page, $perPage);
    }

    /**
     * 访问日志分页。
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginateAccess(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where  = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 同名占位符在 PDO 原生预处理下会 HY093，三个字段各用唯一名。
            $where[] = '(a.ip LIKE :kw_ip OR a.extra LIKE :kw_extra OR k.title LIKE :kw_title)';
            $like    = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
            $params['kw_ip']    = $like;
            $params['kw_extra'] = $like;
            $params['kw_title'] = $like;
        }

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $where[]         = 'a.action = :action';
            $params['action'] = $action;
        }

        $range = self::dateRange($filters);
        if ($range !== null) {
            $where[]        = 'a.created_at BETWEEN :start AND :end';
            $params['start'] = $range[0];
            $params['end']   = $range[1];
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) Database::scalar(
            'SELECT COUNT(*) FROM access_logs a LEFT JOIN cases k ON k.id = a.case_id WHERE ' . $whereSql,
            $params
        );

        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT a.*, k.title AS case_title
                FROM access_logs a
                LEFT JOIN cases k ON k.id = a.case_id
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
     * 登录尝试日志分页。
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginateLoginAttempts(int $page = 1, int $perPage = 30): array
    {
        $perPage = min(200, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $total = (int) Database::scalar('SELECT COUNT(*) FROM login_attempts');
        $items = Database::select(
            'SELECT * FROM login_attempts ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * 导出操作日志为 CSV 文本（需求 4.6 支持导出备份）。
     *
     * @param array<string,mixed> $filters
     */
    public static function exportOperationsCsv(array $filters, int $limit = 5000): string
    {
        $result = self::paginateOperations($filters, 1, min(5000, max(1, $limit)));

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fwrite($handle, "\xEF\xBB\xBF"); // BOM，保证 Excel 打开中文不乱码
        fputcsv($handle, ['ID', '操作人', '操作类型', '对象类型', '对象ID', '操作内容', 'IP地址', '时间']);
        foreach ($result['items'] as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['username'],
                $row['action'],
                $row['target_type'],
                $row['target_id'],
                $row['detail'],
                $row['ip'],
                $row['created_at'],
            ]);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * 日志概览统计。
     *
     * @return array<string,int>
     */
    public static function summary(): array
    {
        return [
            'operations'    => (int) Database::scalar('SELECT COUNT(*) FROM operation_logs'),
            'accesses'      => (int) Database::scalar('SELECT COUNT(*) FROM access_logs'),
            'searches'      => (int) Database::scalar('SELECT COUNT(*) FROM search_logs'),
            'login_failed'  => (int) Database::scalar('SELECT COUNT(*) FROM login_attempts WHERE success = 0'),
            'today_operations' => (int) Database::scalar(
                'SELECT COUNT(*) FROM operation_logs WHERE created_at >= :t',
                ['t' => date('Y-m-d 00:00:00')]
            ),
            'today_accesses' => (int) Database::scalar(
                'SELECT COUNT(*) FROM access_logs WHERE created_at >= :t',
                ['t' => date('Y-m-d 00:00:00')]
            ),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:string}|null
     */
    private static function dateRange(array $filters): ?array
    {
        $start = trim((string) ($filters['start_date'] ?? ''));
        $end   = trim((string) ($filters['end_date'] ?? ''));
        if ($start === '' && $end === '') {
            return null;
        }
        $startTs = $start !== '' ? strtotime($start . ' 00:00:00') : strtotime('-30 days 00:00:00');
        $endTs   = $end !== '' ? strtotime($end . ' 23:59:59') : time();
        if ($startTs === false || $endTs === false) {
            return null;
        }
        return [date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)];
    }
}
