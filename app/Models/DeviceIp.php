<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 车间设备采集 IP 地址模型。
 *
 * 数据来源：MES 开发商《车间设备采集IP地址.xlsx》（PLC 模块、产线工控机、
 * 传感器等采集设备的内网 IP）。核心用途：生产设备损坏更换后，按设备名称
 * 或 IP 反查该位置历史使用的内网地址。
 *
 * 状态位：1=在用，0=已更换（历史存档，换设备后旧 IP 仍可查），2=待审核（前台报备）。
 */
class DeviceIp extends Model
{
    /** @var string */
    protected static $table = 'device_ips';

    /** 在用 */
    public const STATUS_ACTIVE = 1;

    /** 已更换（历史 IP，保留供反查） */
    public const STATUS_RETIRED = 0;

    /** 待审核（前台报备，审核后转在用） */
    public const STATUS_PENDING = 2;

    /** 设备类型（展示顺序即录入顺序） */
    public const TYPES = ['PLC', '工控机', '触摸屏', '传感器', '其他'];

    /** 状态文案 */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE   => '在用',
        self::STATUS_RETIRED  => '已更换',
        self::STATUS_PENDING  => '待审核',
    ];

    /** 前台可见状态（待审核不展示） */
    public const FRONT_STATUSES = [self::STATUS_ACTIVE, self::STATUS_RETIRED];

    /** 关联工序下拉中「自定义新工序」选项的值（选中后弹出名称输入框） */
    public const CUSTOM_CHOICE = '__custom__';

    /** 关键字命中字段 */
    private const SEARCH_FIELDS = ['device_name', 'ip_address', 'remark', 'custom_process'];

    /**
     * 前台检索：关键字 + 设备类型 + 工序（标准工序名或自定义工序名）+ 状态（分页）。
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function search(
        string $keyword,
        string $deviceType,
        string $process,
        int $status,
        int $page,
        int $perPage = 20
    ): array {
        $page   = max(1, $page);
        $where  = ['status = :f_status'];
        $params = ['f_status' => $status];

        if ($keyword !== '') {
            $like     = '%' . self::escapeLike($keyword) . '%';
            $clauses  = [];
            foreach (self::SEARCH_FIELDS as $index => $field) {
                // 原生预处理下同一 SQL 内命名占位符不可复用，逐字段生成唯一名字
                $key               = 'kw_' . $index;
                $clauses[]         = sprintf('%s LIKE :%s', $field, $key);
                $params[$key]      = $like;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
        if ($deviceType !== '') {
            $where[]            = 'device_type = :f_type';
            $params['f_type']   = $deviceType;
        }
        if ($process !== '') {
            // 工序按名称匹配：标准工序走 processes 表，自定义工序走 custom_process 列
            // 注意：原生预处理下同一 SQL 内命名占位符不可复用，拆成两个唯一名字
            $where[]               = '(process_id IN (SELECT id FROM processes WHERE name = :f_process_std)
                                     OR custom_process = :f_process_cus)';
            $params['f_process_std'] = $process;
            $params['f_process_cus'] = $process;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM device_ips WHERE {$whereSql}",
            $params
        );

        $offset            = ($page - 1) * $perPage;
        $itemsParams                 = $params;
        $itemsParams['lim_per_page'] = $perPage;
        $itemsParams['off_offset']   = $offset;

        $items = Database::select(
            "SELECT * FROM device_ips WHERE {$whereSql}
             ORDER BY device_name ASC, ip_address ASC
             LIMIT :lim_per_page OFFSET :off_offset",
            $itemsParams
        );

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * 各设备类型条数（用于筛选云，可按状态过滤）。
     *
     * @return array<string,int> 类型 => 条数
     */
    public static function typeCounts(int $status): array
    {
        $rows = Database::select(
            'SELECT device_type, COUNT(*) AS total FROM device_ips
             WHERE status = :s GROUP BY device_type',
            ['s' => $status]
        );
        $counts = [];
        foreach (self::TYPES as $type) {
            $counts[$type] = 0;
        }
        foreach ($rows as $row) {
            $counts[(string) $row['device_type']] = (int) $row['total'];
        }
        return $counts;
    }

    /**
     * 各状态条数（顶部概览）。
     *
     * @return array{active:int,retired:int,pending:int,total:int}
     */
    public static function statusCounts(): array
    {
        $rows = Database::select('SELECT status, COUNT(*) AS total FROM device_ips GROUP BY status');
        $counts = ['active' => 0, 'retired' => 0, 'pending' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $counts['total'] += (int) $row['total'];
            if ((int) $row['status'] === self::STATUS_ACTIVE) {
                $counts['active'] = (int) $row['total'];
            } elseif ((int) $row['status'] === self::STATUS_RETIRED) {
                $counts['retired'] = (int) $row['total'];
            } else {
                $counts['pending'] = (int) $row['total'];
            }
        }
        return $counts;
    }

    /**
     * 后台列表：关键字 + 类型 + 状态 + 工序（标准工序名或自定义工序名）过滤（分页）。
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function adminSearch(
        string $keyword,
        string $deviceType,
        int $status,
        string $process,
        int $page,
        int $perPage = 20
    ): array {
        $where  = ['1 = 1'];
        $params = [];

        if ($keyword !== '') {
            $like     = '%' . self::escapeLike($keyword) . '%';
            $clauses  = [];
            foreach (self::SEARCH_FIELDS as $index => $field) {
                $key           = 'akw_' . $index;
                $clauses[]     = sprintf('%s LIKE :%s', $field, $key);
                $params[$key]  = $like;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
        if ($deviceType !== '') {
            $where[]          = 'device_type = :a_type';
            $params['a_type'] = $deviceType;
        }
        if ($status >= 0) {
            $where[]           = 'status = :a_status';
            $params['a_status'] = $status;
        }
        if ($process !== '') {
            // 工序按名称匹配：标准工序走 processes 表，自定义工序走 custom_process 列
            // 注意：原生预处理下同一 SQL 内命名占位符不可复用，拆成两个唯一名字
            $where[]               = '(process_id IN (SELECT id FROM processes WHERE name = :a_process_std)
                                     OR custom_process = :a_process_cus)';
            $params['a_process_std'] = $process;
            $params['a_process_cus'] = $process;
        }

        return self::paginate(
            implode(' AND ', $where),
            $params,
            // 待审核（status=2）排最前，避免管理员翻到最后一页才看到报备
            'CASE WHEN status = 2 THEN 0 ELSE 1 END, status ASC, device_name ASC, ip_address ASC',
            $page,
            $perPage
        );
    }

    /**
     * 待审核的前台报备（后台首页「待处理」面板用，按报备时间倒序）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pendingList(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::select(
            "SELECT * FROM device_ips
             WHERE status = :s
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit}",
            ['s' => self::STATUS_PENDING]
        );
    }

    /**
     * IP 冲突检测：同一 IP 被多条「在用」记录占用（源表存在此类脏数据）。
     *
     * @return array<int,array{ip_address:string,total:int,names:string}>
     */
    public static function ipConflicts(): array
    {
        $rows = Database::select(
            "SELECT ip_address, COUNT(*) AS total,
                    GROUP_CONCAT(device_name ORDER BY device_name SEPARATOR '、') AS names
             FROM device_ips
             WHERE status = :s AND ip_address <> ''
             GROUP BY ip_address
             HAVING COUNT(*) > 1
             ORDER BY total DESC, ip_address ASC",
            ['s' => self::STATUS_ACTIVE]
        );
        return $rows;
    }

    /**
     * 未配置 IP 的在用设备（源表存在此类脏数据：导入时地址缺失）。
     *
     * 与 ipConflicts() 同属「数据完整性」类提示，供后台顶部告警条使用。
     * 只取在用记录——已更换(status=0)的设备本就不再占用地址，无需催补。
     *
     * @return array<int,array{id:int,device_name:string,device_type:string}>
     */
    public static function missingIpList(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        return Database::select(
            "SELECT id, device_name, device_type
             FROM device_ips
             WHERE status = :s AND (ip_address IS NULL OR TRIM(ip_address) = '')
             ORDER BY device_name ASC
             LIMIT {$limit}",
            ['s' => self::STATUS_ACTIVE]
        );
    }

    /**
     * 同 IP 近期报备次数（前台限流）。
     */
    public static function countRecentByIp(string $ip, int $minutes): int
    {
        if ($ip === '') {
            return 0;
        }
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM device_ips
             WHERE submit_ip = :ip AND source = :src
               AND created_at >= DATE_SUB(NOW(), INTERVAL :mins MINUTE)',
            ['ip' => $ip, 'src' => 'submit', 'mins' => $minutes]
        );
    }

    /**
     * 完全重复判断（后台保存用）：同名称 + 同 IP + 同备注视为重复。
     */
    public static function isDuplicate(array $fields, int $excludeId = 0): bool
    {
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM device_ips
             WHERE device_name = :d_name AND ip_address = :d_ip
               AND remark = :d_remark AND id <> :d_id',
            [
                'd_name'   => $fields['device_name'],
                'd_ip'     => $fields['ip_address'],
                'd_remark' => $fields['remark'],
                'd_id'     => $excludeId,
            ]
        );
        return $count > 0;
    }

    /**
     * 导入判重键：设备名称 + IP + 备注（与后台手写查重口径一致）。
     *
     * @param array<string,mixed> $row
     */
    public static function importKey(array $row): string
    {
        $parts = [];
        foreach (['device_name', 'ip_address', 'remark'] as $field) {
            $parts[] = trim((string) ($row[$field] ?? ''));
        }
        return implode("\x1f", $parts);
    }

    /**
     * 批量导入预检：只统计，不写库。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{new:int,dup:int,newRows:array<int,array<string,mixed>>}
     */
    public static function previewImport(array $rows): array
    {
        $existing = self::existingKeys();
        $new      = 0;
        $dup      = 0;
        $newRows  = [];

        foreach ($rows as $row) {
            $key = self::importKey($row);
            if (isset($existing[$key])) {
                $dup++;
                continue;
            }
            $existing[$key] = true;
            $new++;
            $newRows[] = $row;
        }

        return ['new' => $new, 'dup' => $dup, 'newRows' => $newRows];
    }

    /**
     * 合并导入（xlsx 批量补充）：仅补入库中不存在的行，后台编辑过的数据一律保留。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{inserted:int,skipped:int}
     */
    public static function mergeImport(array $rows): array
    {
        $existing = self::existingKeys();
        $inserted = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $key = self::importKey($row);
            if (isset($existing[$key])) {
                $skipped++;
                continue;
            }

            self::create([
                'device_name' => (string) ($row['device_name'] ?? ''),
                'ip_address'  => (string) ($row['ip_address'] ?? ''),
                'device_type' => (string) ($row['device_type'] ?? '其他'),
                'process_id'  => (int) ($row['process_id'] ?? 0),
                'status'      => self::STATUS_ACTIVE,
                'remark'      => (string) ($row['remark'] ?? ''),
                'source'      => 'import',
            ]);

            $existing[$key] = true;
            $inserted++;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * 从设备名称推断类型（导入与前台报备共用，后台可再修正）。
     */
    public static function guessType(string $deviceName): string
    {
        $name = strtolower($deviceName);
        if (strpos($name, 'plc') !== false) {
            return 'PLC';
        }
        foreach (['触摸屏', 'hmi', '人机屏', '显示屏'] as $needle) {
            if (strpos($name, $needle) !== false) {
                return '触摸屏';
            }
        }
        if (strpos($name, '电脑') !== false) {
            return '工控机';
        }
        foreach (['凹凸火花', '温湿度仪', '水槽温度'] as $needle) {
            if (strpos($name, $needle) !== false) {
                return '传感器';
            }
        }
        return '其他';
    }

    /**
     * 从设备名称推断关联工序（保守映射，识别不出的归 0，后台可补）。
     */
    public static function guessProcessId(string $deviceName): int
    {
        // 定稿 7 工序 id：绞线 1 / 绝缘押出 3 / 成缆 2 / 编织 6 / 护套押出 4 / 倒线 17 / 数据缆 5
        $map = [
            '绞线'   => 1,
            '绝缘'   => 3,
            '成缆'   => 2,
            '包带'   => 2, // 包带机隶属成缆工序
            '护套'   => 4,
            '编织'   => 6,
            '倒线'   => 17,
            '数据缆' => 5,
        ];
        foreach ($map as $needle => $pid) {
            if (strpos($deviceName, $needle) !== false) {
                return $pid;
            }
        }
        return 0;
    }

    /**
     * 已使用的自定义关联工序名称（去重，按名称排序）。
     *
     * 后台/前台录入自定义工序后，该名称自动出现在「关联工序」下拉与筛选名单中。
     *
     * @return array<int,string>
     */
    public static function customProcessNames(): array
    {
        $rows = Database::select(
            "SELECT DISTINCT custom_process FROM device_ips
             WHERE custom_process <> ''
             ORDER BY custom_process ASC"
        );
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row['custom_process'];
        }
        return $names;
    }

    /**
     * 解析「关联工序」提交值，返回标准工序 id 与自定义工序名（二者互斥）。
     *
     * 下拉取值约定：'0' / '' = 未绑定；标准工序名 = 绑定 processes 表；
     * CUSTOM_CHOICE = 使用 $customText 作为自定义工序名（process_id 置 0）。
     * 容错：既非标准工序也非标记的其它非空值，按自定义工序名直接接纳。
     *
     * @param array<int,array<string,mixed>> $processes
     * @return array{process_id:int,custom_process:string}
     */
    public static function resolveProcess(array $processes, string $choice, string $customText): array
    {
        $choice = trim($choice);
        if ($choice === '' || $choice === '0') {
            return ['process_id' => 0, 'custom_process' => ''];
        }

        if ($choice === self::CUSTOM_CHOICE) {
            return ['process_id' => 0, 'custom_process' => self::normalizeProcessName($customText)];
        }

        foreach ($processes as $proc) {
            if ((string) $proc['name'] === $choice) {
                return ['process_id' => (int) $proc['id'], 'custom_process' => ''];
            }
        }

        return ['process_id' => 0, 'custom_process' => self::normalizeProcessName($choice)];
    }

    /**
     * 自定义工序名称归一化：去首尾空白、去控制字符、压缩连续空白、截断到 100 字。
     */
    public static function normalizeProcessName(string $name): string
    {
        $name = trim($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        return mb_substr(trim($name), 0, 100, 'UTF-8');
    }

    /**
     * 记录的工序展示名：优先标准工序名，其次自定义工序名，都没有返回空串（调用方展示「—」）。
     *
     * @param array<string,mixed>           $item
     * @param array<int,string>             $processNames 工序 id => 工序名
     */
    public static function processLabel(array $item, array $processNames): string
    {
        $pid = (int) ($item['process_id'] ?? 0);
        if ($pid > 0 && isset($processNames[$pid])) {
            return (string) $processNames[$pid];
        }
        $custom = trim((string) ($item['custom_process'] ?? ''));
        return $custom !== '' ? $custom : '';
    }

    /**
     * 现有记录判重键集合。
     *
     * @return array<string,bool>
     */
    private static function existingKeys(): array
    {
        $keys = [];
        $rows = Database::select('SELECT device_name, ip_address, remark FROM device_ips');
        foreach ($rows as $row) {
            $keys[self::importKey($row)] = true;
        }
        return $keys;
    }

    /**
     * LIKE 转义：避免关键字中的 % 与 _ 被当作通配符。
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
