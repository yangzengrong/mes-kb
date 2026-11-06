<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 员工反馈（前台提交 MES 疑难问题 -> 后台受理并生成故障案例）。
 *
 * 状态流转：
 *   pending 待受理 --> processing 处理中 --> resolved 已解决（已生成并发布案例）
 *   任意未解决状态 --> closed 已关闭（填写关闭原因）
 */
class Feedback extends Model
{
    protected static $table = 'feedbacks';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RESOLVED   = 'resolved';
    public const STATUS_CLOSED     = 'closed';

    public const URGENCY_LOW    = 'low';
    public const URGENCY_NORMAL = 'normal';
    public const URGENCY_HIGH   = 'high';
    public const URGENCY_URGENT = 'urgent';

    /**
     * 生成反馈单号：F + 年月日 + 当日序号（如 F260912-0007）。
     * 以当日已有条数为基准递增，遇并发冲突自动重试。
     */
    public static function generateCode(): string
    {
        $prefix = 'F' . date('ymd') . '-';
        $base   = (int) Database::scalar(
            'SELECT COUNT(*) FROM feedbacks WHERE code LIKE :p',
            ['p' => $prefix . '%']
        );

        for ($i = 1; $i <= 50; $i++) {
            $code   = $prefix . str_pad((string) ($base + $i), 4, '0', STR_PAD_LEFT);
            $exists = (int) Database::scalar('SELECT COUNT(*) FROM feedbacks WHERE code = :c', ['c' => $code]);
            if ($exists === 0) {
                return $code;
            }
        }

        // 极端情况兜底：随机后缀，保证不阻塞提交
        return $prefix . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * 列表 / 全量查询共用的 WHERE 拼装（反馈列表筛选）。
     *
     * 只统计未删除记录，trashed 视图由列表页单独处理，这里不承接。
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function buildFilterWhere(array $filters): array
    {
        $where  = ['f.deleted_at IS NULL'];
        $params = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[]      = '(f.code LIKE :kw OR f.title LIKE :kw2 OR f.employee_name LIKE :kw3 OR f.department LIKE :kw4 OR f.device LIKE :kw5)';
            $like         = '%' . self::escapeLike($keyword) . '%';
            // PDO 原生预处理下同一命名占位符不可复用，拆成多个参数
            foreach (['kw', 'kw2', 'kw3', 'kw4', 'kw5'] as $key) {
                $params[$key] = $like;
            }
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && array_key_exists($status, feedback_status_map())) {
            $where[]          = 'f.status = :status';
            $params['status'] = $status;
        }

        $urgency = trim((string) ($filters['urgency'] ?? ''));
        if ($urgency !== '' && array_key_exists($urgency, feedback_urgency_map())) {
            $where[]           = 'f.urgency = :urgency';
            $params['urgency'] = $urgency;
        }

        $processId = (int) ($filters['process_id'] ?? 0);
        if ($processId > 0) {
            $where[]       = 'f.process_id = :pid';
            $params['pid'] = $processId;
        }

        // 按提交月份筛选（YYYY-MM，闭开区间：当月 1 日 ≤ created_at < 次月 1 日）
        $month = trim((string) ($filters['month'] ?? ''));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            $start           = $month . '-01 00:00:00';
            $end             = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
            $where[]         = 'f.created_at >= :m_from AND f.created_at < :m_to';
            $params['m_from'] = $start;
            $params['m_to']   = $end;
        }

        // 闭环维度：已解决 / 已关闭 = 闭环，待受理 / 处理中 = 未闭环
        $closure = trim((string) ($filters['closure'] ?? ''));
        if ($closure === 'closed') {
            $where[] = "f.status IN ('resolved', 'closed')";
        } elseif ($closure === 'open') {
            $where[] = "f.status IN ('pending', 'processing')";
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * 后台列表（含工序名、问题类型名、处理人、关联案例标题）。
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginateByFilters(array $filters, int $page = 1, int $perPage = 15): array
    {
        [$whereSql, $params] = self::buildFilterWhere($filters);
        $total    = (int) Database::scalar('SELECT COUNT(*) FROM feedbacks f WHERE ' . $whereSql, $params);

        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // 排序：待受理优先，其次紧急程度，最后提交时间倒序
        $sql = 'SELECT f.*, p.name AS process_name, c.name AS category_name, u.real_name AS handler_name, k.title AS case_title, k.status AS case_status
                FROM feedbacks f
                LEFT JOIN processes p ON p.id = f.process_id
                LEFT JOIN categories c ON c.id = f.category_id
                LEFT JOIN users u ON u.id = f.handler_id
                LEFT JOIN cases k ON k.id = f.case_id
                WHERE ' . $whereSql . '
                ORDER BY FIELD(f.status, \'pending\', \'processing\', \'resolved\', \'closed\'),
                         FIELD(f.urgency, \'urgent\', \'high\', \'normal\', \'low\'),
                         f.created_at DESC
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
     * 全量查询（CSV 导出等，不受分页限制）。
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function allByFilters(array $filters): array
    {
        [$whereSql, $params] = self::buildFilterWhere($filters);
        $sql = 'SELECT f.*, p.name AS process_name, c.name AS category_name, u.real_name AS handler_name, k.title AS case_title, k.status AS case_status
                FROM feedbacks f
                LEFT JOIN processes p ON p.id = f.process_id
                LEFT JOIN categories c ON c.id = f.category_id
                LEFT JOIN users u ON u.id = f.handler_id
                LEFT JOIN cases k ON k.id = f.case_id
                WHERE ' . $whereSql . '
                ORDER BY FIELD(f.status, \'pending\', \'processing\', \'resolved\', \'closed\'),
                         FIELD(f.urgency, \'urgent\', \'high\', \'normal\', \'low\'),
                         f.created_at DESC';
        return Database::select($sql, $params);
    }

    /**
     * 工序 × 状态 交叉统计（类 Excel 透视表）：
     * 行为各工序（含「未指定」），列为各状态 + 合计，并给出总计行。
     * 传入 $filters 时仅统计符合条件的记录（如指定月份）。
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<string,array<string,int>>,grand:array<string,int>,statuses:array<int,string>}
     */
    public static function processStatusMatrix(array $filters = []): array
    {
        [$whereSql, $params] = self::buildFilterWhere($filters);
        $rows = Database::select(
            'SELECT COALESCE(p.name, \'未指定\') AS process_name, f.status, COUNT(*) AS total
             FROM feedbacks f LEFT JOIN processes p ON p.id = f.process_id
             WHERE ' . $whereSql . '
             GROUP BY COALESCE(p.name, \'未指定\'), f.status',
            $params
        );
        $statusKeys = array_keys(feedback_status_map());

        $matrix = [];
        foreach ($rows as $row) {
            $name = (string) $row['process_name'];
            if (!isset($matrix[$name])) {
                $matrix[$name] = array_fill_keys($statusKeys, 0);
                $matrix[$name]['total'] = 0;
            }
            $matrix[$name][(string) $row['status']] = (int) $row['total'];
            $matrix[$name]['total'] += (int) $row['total'];
        }

        // 按合计降序（未指定强制置底）
        uasort($matrix, static function (array $a, array $b): int {
            $an = ($a['total'] ?? 0);
            $bn = ($b['total'] ?? 0);
            if ($bn === $an) {
                return 0;
            }
            return $bn <=> $an;
        });
        if (isset($matrix['未指定'])) {
            $un = $matrix['未指定'];
            unset($matrix['未指定']);
            $matrix['未指定'] = $un;
        }

        $grand = array_fill_keys($statusKeys, 0);
        $grand['total'] = 0;
        foreach ($matrix as $row) {
            foreach ($statusKeys as $key) {
                $grand[$key] += (int) ($row[$key] ?? 0);
            }
            $grand['total'] += (int) ($row['total'] ?? 0);
        }

        return ['rows' => $matrix, 'grand' => $grand, 'statuses' => $statusKeys];
    }

    /**
     * 详情（含工序名、处理人、关联案例）。
     *
     * @return array<string,mixed>|null
     */
    public static function findWithMeta(int $id): ?array
    {
        return Database::selectOne(
            'SELECT f.*, p.name AS process_name, c.name AS category_name, u.real_name AS handler_name,
                    k.title AS case_title, k.status AS case_status
             FROM feedbacks f
             LEFT JOIN processes p ON p.id = f.process_id
             LEFT JOIN categories c ON c.id = f.category_id
             LEFT JOIN users u ON u.id = f.handler_id
             LEFT JOIN cases k ON k.id = f.case_id
             WHERE f.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * 前台进度查询：单号 + 员工姓名同时匹配才可查看。
     *
     * @return array<string,mixed>|null
     */
    public static function findForTrack(string $code, string $name): ?array
    {
        $code = trim($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            return null;
        }
        return Database::selectOne(
            'SELECT f.*, p.name AS process_name
             FROM feedbacks f
             LEFT JOIN processes p ON p.id = f.process_id
             WHERE f.code = :c AND f.employee_name = :n AND f.deleted_at IS NULL LIMIT 1',
            ['c' => $code, 'n' => $name]
        );
    }

    /**
     * 各状态数量（后台侧边栏角标 / 列表页统计）。
     *
     * @return array<string,int>
     */
    public static function statusCounts(): array
    {
        $rows   = Database::select(
            'SELECT status, COUNT(*) AS total FROM feedbacks WHERE deleted_at IS NULL GROUP BY status'
        );
        $result = array_fill_keys(array_keys(feedback_status_map()), 0);
        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }
        $result['total'] = array_sum($result);
        return $result;
    }

    public static function pendingCount(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM feedbacks WHERE deleted_at IS NULL AND status = :s',
            ['s' => self::STATUS_PENDING]
        );
    }

    /**
     * 反馈统计聚合数据（仅统计未删除记录）。
     *
     * 传入 $filters 时按筛选条件聚合：后台「反馈统计」用 month 锁定某个月，
     * 供月度会议查看「上月反馈量 + 各维度分布 + 闭环情况」。
     * 返回的均为聚合计数，不含任何个人信息。
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function stats(array $filters = []): array
    {
        [$whereSql, $params] = self::buildFilterWhere($filters);

        // 状态分布
        $statusRows = Database::select(
            'SELECT f.status, COUNT(*) AS total FROM feedbacks f WHERE ' . $whereSql . ' GROUP BY f.status',
            $params
        );
        $statusCounts = array_fill_keys(array_keys(feedback_status_map()), 0);
        foreach ($statusRows as $row) {
            $statusCounts[(string) $row['status']] = (int) $row['total'];
        }
        $total = array_sum($statusCounts);

        // 紧急程度分布
        $urgencyRows = Database::select(
            'SELECT f.urgency, COUNT(*) AS total FROM feedbacks f WHERE ' . $whereSql . ' GROUP BY f.urgency',
            $params
        );
        $urgencyCounts = array_fill_keys(array_keys(feedback_urgency_map()), 0);
        foreach ($urgencyRows as $row) {
            $urgencyCounts[(string) $row['urgency']] = (int) $row['total'];
        }

        // 按工序分布
        $processRows = Database::select(
            'SELECT COALESCE(p.name, \'未指定\') AS name, COUNT(*) AS total
             FROM feedbacks f LEFT JOIN processes p ON p.id = f.process_id
             WHERE ' . $whereSql . '
             GROUP BY COALESCE(p.name, \'未指定\') ORDER BY total DESC LIMIT 12',
            $params
        );

        // 按问题类型分布
        $categoryRows = Database::select(
            'SELECT COALESCE(c.name, \'未指定\') AS name, COUNT(*) AS total
             FROM feedbacks f LEFT JOIN categories c ON c.id = f.category_id
             WHERE ' . $whereSql . '
             GROUP BY COALESCE(c.name, \'未指定\') ORDER BY total DESC LIMIT 12',
            $params
        );

        // 按部门 Top 10
        $deptRows = Database::select(
            'SELECT CASE WHEN f.department = \'\' THEN \'未填写\' ELSE f.department END AS name, COUNT(*) AS total
             FROM feedbacks f WHERE ' . $whereSql . '
             GROUP BY name ORDER BY total DESC LIMIT 10',
            $params
        );

        // 闭环：已解决 + 已关闭 = 闭环，待受理 + 处理中 = 未闭环
        $closedCount  = $statusCounts[self::STATUS_RESOLVED] + $statusCounts[self::STATUS_CLOSED];
        $openCount    = $total - $closedCount;
        $resolvedRate = $total > 0 ? round($closedCount / $total * 100, 1) : 0.0;

        // 平均处理时长（分钟 -> 小时）
        $avgMinutes = (float) Database::scalar(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, f.created_at, f.handled_at))
             FROM feedbacks f WHERE ' . $whereSql . ' AND f.handled_at IS NOT NULL
                AND f.status IN (\'resolved\', \'closed\')',
            $params
        );
        $avgHandleHours = $avgMinutes > 0 ? round($avgMinutes / 60, 1) : null;

        return [
            'total'          => $total,
            'status'         => $statusCounts,
            'urgency'        => $urgencyCounts,
            'process'        => $processRows,
            'category'       => $categoryRows,
            'department'     => $deptRows,
            'closed'         => $closedCount,
            'open'           => $openCount,
            'resolved_rate'  => $resolvedRate,
            'avg_handle_hours' => $avgHandleHours,
            'generated_at'   => now(),
        ];
    }

    /**
     * 有反馈提交的月份列表（最新在前），供「月份」下拉选择。
     *
     * @return array<int,array{ym:string,total:int}>
     */
    public static function availableMonths(int $limit = 36): array
    {
        return Database::select(
            'SELECT DATE_FORMAT(created_at, \'%Y-%m\') AS ym, COUNT(*) AS total
             FROM feedbacks WHERE deleted_at IS NULL
             GROUP BY ym ORDER BY ym DESC LIMIT ' . (int) $limit
        );
    }

    /**
     * 同一 IP 在指定分钟内的提交次数（防刷单）。
     */
    public static function countRecentByIp(string $ip, int $minutes = 60): int
    {
        if ($ip === '') {
            return 0;
        }
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM feedbacks WHERE ip = :ip AND created_at >= :since',
            ['ip' => $ip, 'since' => date('Y-m-d H:i:s', time() - max(1, $minutes) * 60)]
        );
    }

    /**
     * 受理 / 关闭：更新状态与处理说明。
     */
    public static function updateStatus(int $id, string $status, int $handlerId, string $note): void
    {
        $payload = [
            'status'     => $status,
            'updated_at' => now(),
        ];
        if ($note !== '') {
            $payload['admin_note'] = $note;
        }
        $now = now();
        if (in_array($status, [self::STATUS_PROCESSING, self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            $payload['handler_id'] = $handlerId;
            $payload['handled_at'] = $now;
        }
        // 完成时间只对「已解决 / 已关闭」写入；重新打开（处理中 / 待受理）时清空，
        // 避免列表页「完成时间」残留一条已作废的旧值。
        $payload['completed_at'] = in_array($status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)
            ? $now
            : null;
        self::modify($id, $payload);
    }

    /**
     * 关联生成的案例，并置为已解决。
     */
    public static function linkCase(int $id, int $caseId, int $handlerId, string $note): void
    {
        $now = now();
        self::modify($id, [
            'case_id'      => $caseId,
            'status'       => self::STATUS_RESOLVED,
            'handler_id'   => $handlerId,
            'handled_at'   => $now,
            'completed_at' => $now,
            'admin_note'   => $note,
            'updated_at'   => $now,
        ]);
    }

    public static function moveToTrash(int $id): int
    {
        return self::modify($id, ['deleted_at' => now(), 'updated_at' => now()]);
    }

    public static function restore(int $id): int
    {
        return self::modify($id, ['deleted_at' => null, 'updated_at' => now()]);
    }

    /**
     * 把纯文本反馈内容转成可入库的富文本 HTML（案例 phenomenon 字段）。
     */
    public static function textToHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }
        $paragraphs = explode("\n", $text);
        $html       = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html .= '<p>' . nl2br(e($paragraph)) . '</p>';
        }
        return $html !== '' ? $html : '<p>' . e($text) . '</p>';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
