<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 故障案例模型（需求 4.3 故障案例全生命周期管理）。
 *
 * 状态流转：
 *   pending 待审核 --审核通过--> published 已发布 --下架--> offline 已下架 --重新发布--> published
 *   published/offline --到期--> expired 已过期
 *   任意状态 --删除--> 回收站（deleted_at 非空）--恢复--> 原状态 / --彻底删除--> 物理删除（仅超管）
 */
class CaseModel extends Model
{
    protected static $table = 'cases';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_OFFLINE   = 'offline';
    public const STATUS_EXPIRED   = 'expired';

    /** 前台检索参与匹配的字段 */
    private const SEARCH_FIELDS = ['title', 'device', 'error_code', 'phenomenon', 'cause', 'solution', 'prevention', 'tags'];

    // =========================================================
    // 后台列表
    // =========================================================

    /**
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function paginateByFilters(array $filters, int $page = 1, int $perPage = 15): array
    {
        $where  = [];
        $params = [];

        if (empty($filters['trashed'])) {
            $where[] = 'c.deleted_at IS NULL';
        } else {
            $where[] = 'c.deleted_at IS NOT NULL';
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // PDO 原生预处理（ATTR_EMULATE_PREPARES=false）下同名占位符不可复用：
            // 一个命名出现 N 次就必须绑定 N 个参数，否则 HY093。
            // 故三个字段各用一个唯一名，不要图省事写三个 :kw。
            $where[] = '(c.title LIKE :kw_title OR c.device LIKE :kw_device OR c.error_code LIKE :kw_code)';
            $like    = '%' . self::escapeLike($keyword) . '%';
            $params['kw_title']  = $like;
            $params['kw_device'] = $like;
            $params['kw_code']   = $like;
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            // PDO 原生预处理下同一命名占位符不能复用，拆成两个参数（防 HY093）。
            $where[]              = '(c.category_id = :cid_self OR c.category_id IN (SELECT id FROM categories WHERE parent_id = :cid_child))';
            $params['cid_self']   = $categoryId;
            $params['cid_child']  = $categoryId;
        }

        // 线缆专属改版：后台列表按工序筛选
        $listProcessId = (int) ($filters['process_id'] ?? 0);
        if ($listProcessId > 0) {
            $where[]             = 'c.process_id = :lpid';
            $params['lpid']      = $listProcessId;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && array_key_exists($status, case_status_map())) {
            $where[]          = 'c.status = :status';
            $params['status'] = $status;
        }

        /* 「发布后无人看」下钻（2026-10-26）。
           【为什么要有这个筛选】看板上「发布后无人看 17」原本只是一个数字：
           知道有 17 条发了没人看，却不知道是哪 17 条，等于给了一个无法行动的指标。
           这里支持从看板卡片直接点进来筛出「已发布 + 0 浏览」的明细。
           条件与 CaseModel::zeroViewCount() 严格一致（同一口径），
           否则卡片数字和点进去的条数对不上，比不给入口更糟。 */
        if (!empty($filters['zero_view'])) {
            $where[] = "c.status = 'published' AND c.views = 0";
        }

        // 文档类型单一过滤（后台「系统操作手册 / 版本更新日志」独立管理入口）
        $listDocType = trim((string) ($filters['doc_type'] ?? ''));
        if ($listDocType !== '' && array_key_exists($listDocType, doc_type_map())) {
            $where[]         = 'c.doc_type = :list_dtype';
            $params['list_dtype'] = $listDocType;
        }

        // 运维管理员仅能看到自己创建的案例（对应需求 4.1 权限隔离）
        $authorId = (int) ($filters['author_id'] ?? 0);
        if ($authorId > 0) {
            $where[]            = 'c.author_id = :author_id';
            $params['author_id'] = $authorId;
        }

        $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

        $total  = (int) Database::scalar('SELECT COUNT(*) FROM cases c WHERE ' . $whereSql, $params);
        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT c.*, cat.name AS category_name, p.name AS process_name, u.username AS author_name
                FROM cases c
                LEFT JOIN categories cat ON cat.id = c.category_id
                LEFT JOIN processes p ON p.id = c.process_id
                LEFT JOIN users u ON u.id = c.author_id
                WHERE ' . $whereSql . '
                ORDER BY c.is_top DESC, c.updated_at DESC, c.id DESC
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

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
     * 后台详情（含分类名与作者名）。
     *
     * @return array<string,mixed>|null
     */
    public static function findWithMeta(int $id, bool $withTrashed = true): ?array
    {
        $sql = 'SELECT c.*, cat.name AS category_name, u.username AS author_name
                FROM cases c
                LEFT JOIN categories cat ON cat.id = c.category_id
                LEFT JOIN users u ON u.id = c.author_id
                WHERE c.id = :id' . ($withTrashed ? '' : ' AND c.deleted_at IS NULL') . ' LIMIT 1';
        return Database::selectOne($sql, ['id' => $id]);
    }

    // =========================================================
    // 前台检索与展示
    // =========================================================

    /**
     * 全文模糊搜索：故障标题、设备名称、报错代码、故障现象、解决方案等。
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function search(array $filters, int $page = 1, int $perPage = 10): array
    {
        $where  = ["c.status = 'published'", 'c.deleted_at IS NULL'];
        $params = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // ① 同义词扩展：搜「扫描枪」也能命中写着「扫码枪」的案例（后台可维护词表）
            // ② 拼音首字母：搜「smq」等价于同时检索「扫码枪」（词表来自案例/菜单/工艺术语）
            $terms  = Synonym::expand($keyword);
            foreach (PinyinTerm::matchAbbr($keyword, 3) as $pinyinWord) {
                if (!in_array($pinyinWord, $terms, true)) {
                    $terms[] = $pinyinWord;
                }
            }
            $groups = [];
            foreach ($terms as $termIndex => $term) {
                $clauses = [];
                foreach (self::SEARCH_FIELDS as $index => $field) {
                    $key          = 'kw' . $termIndex . '_' . $index;
                    $clauses[]    = sprintf('c.`%s` LIKE :%s', $field, $key);
                    $params[$key] = '%' . self::escapeLike($term) . '%';
                }
                $groups[] = '(' . implode(' OR ', $clauses) . ')';
            }
            $where[] = '(' . implode(' OR ', $groups) . ')';
        }

        // 故障类型：多选（导航栏优化：高级筛选支持多选），兼容旧单选 category_id
        $categoryIds = array_values(array_filter(array_map('intval', (array) ($filters['category_ids'] ?? [])), static function (int $id): bool {
            return $id > 0;
        }));
        if ($categoryIds === [] && (int) ($filters['category_id'] ?? 0) > 0) {
            $categoryIds = [(int) $filters['category_id']];
        }
        if ($categoryIds !== []) {
            // PDO 原生预处理（EMULATE_PREPARES=false）下同一命名占位符不能复用，
            // 动态生成唯一占位符（:cids0/:cidc0 ...），防止 SQLSTATE[HY093]。
            $mainPlaceholders = [];
            $childPlaceholders = [];
            foreach ($categoryIds as $index => $categoryId) {
                $mainPlaceholders[]          = ':cids' . $index;
                $params['cids' . $index]     = $categoryId;
                $childPlaceholders[]         = ':cidc' . $index;
                $params['cidc' . $index]     = $categoryId;
            }
            $where[] = '(c.category_id IN (' . implode(',', $mainPlaceholders) . ')
                OR c.category_id IN (SELECT id FROM categories WHERE parent_id IN (' . implode(',', $childPlaceholders) . ')))';
        }

        // 线缆专属改版：工序（一级）筛选
        $processId = (int) ($filters['process_id'] ?? 0);
        if ($processId > 0) {
            $where[]           = 'c.process_id = :pid';
            $params['pid']     = $processId;
        }

        // 系统操作手册：按匹配岗位筛选。
        // 故障案例仍按原语义精确匹配岗位；手册额外带上 position_id=0 的通用手册（全岗位可见）。
        $positionId = (int) ($filters['position_id'] ?? 0);
        if ($positionId > 0) {
            $where[]         = '(c.position_id = :posid OR (c.doc_type = \'manual\' AND c.position_id = 0))';
            $params['posid'] = $positionId;
        }

        // 文档类型：多选（导航栏优化），兼容旧单选 doc_type
        $allowedDocTypes = array_keys(doc_type_map());
        $docTypes = array_values(array_intersect($allowedDocTypes, array_map('strval', (array) ($filters['doc_types'] ?? []))));
        if ($docTypes === [] && in_array((string) ($filters['doc_type'] ?? ''), $allowedDocTypes, true)) {
            $docTypes = [(string) $filters['doc_type']];
        }
        if ($docTypes !== []) {
            $docPlaceholders = [];
            foreach ($docTypes as $index => $docType) {
                $docPlaceholders[]        = ':dtype' . $index;
                $params['dtype' . $index] = $docType;
            }
            $where[] = 'c.doc_type IN (' . implode(',', $docPlaceholders) . ')';
        }

        // 标签关键字：精确匹配逗号分隔标签中的某一项（后台发布时手动输入）
        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            $where[]         = "CONCAT(',', c.tags, ',') LIKE :tagkw";
            $params['tagkw'] = '%,' . self::escapeLike($tag) . ',%';
        }

        $whereSql = implode(' AND ', $where);

        $total  = (int) Database::scalar('SELECT COUNT(*) FROM cases c WHERE ' . $whereSql, $params);
        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT c.id, c.title, c.device, c.error_code, c.tags, c.phenomenon, c.cause, c.solution,
                       c.views, c.published_at, c.updated_at, c.is_top, c.doc_type, c.process_id, c.position_id,
                       cat.name AS category_name, p.name AS process_name, po.name AS position_name
                FROM cases c
                LEFT JOIN categories cat ON cat.id = c.category_id
                LEFT JOIN processes p ON p.id = c.process_id
                LEFT JOIN positions po ON po.id = c.position_id
                WHERE ' . $whereSql . '
                ORDER BY c.is_top DESC, c.published_at DESC, c.id DESC
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

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
     * 低精度回退检索（断点A 智能降级检索的第 ② 条出路）。
     *
     * 【为什么需要】
     * 主检索 search() 会在 8 个字段里做全字段 LIKE 匹配，条件很严：
     * 用户输入「扫码枪打印机 MSQ-200 报 ERROR 3」这样带型号和错误码的长串时，
     * 整串几乎不可能出现在任何单个字段里，于是零结果 —— 但用户真正想找的
     * 可能就是那条标题里含「扫码枪」的案例。
     *
     * 【怎么做】
     * 1) 剔除噪音：把过长且由型号/错误码构成的片段丢掉（见 splitKeyword）
     * 2) 降级字段：只用 title / device / error_code / tags 四个高精度字段，
     *    放弃 phenomenon / cause / solution 三个大段正文 —— 正文里出现关键词
     *    往往只是顺带提及，不代表主题相关
     * 3) 逐段 OR：拆出的每一段都单独参与匹配，任一段命中即返回
     *
     * 【与主检索的关系】
     * 只在主检索 total === 0 时被调用（见 HomeController::search），
     * 不影响正常检索路径，也不改变排序规则。
     *
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function relaxedSearch(string $keyword, int $limit = 5): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['items' => [], 'total' => 0];
        }

        $segments = self::splitKeyword($keyword);
        if ($segments === []) {
            return ['items' => [], 'total' => 0];
        }

        $where  = ["c.status = 'published'", 'c.deleted_at IS NULL'];
        $params = [];

        // 只用高精度字段（标题/设备/错误码/标签），放弃正文大段
        $relaxedFields = ['title', 'device', 'error_code', 'tags'];
        $groups        = [];

        foreach ($segments as $i => $segment) {
            $clauses = [];
            foreach ($relaxedFields as $j => $field) {
                // PDO 原生预处理下同名占位符不可复用，逐段逐字段唯一命名
                $key         = 'rx' . $i . '_' . $j;
                $clauses[]   = sprintf('c.`%s` LIKE :%s', $field, $key);
                $params[$key] = '%' . self::escapeLike($segment) . '%';
            }
            $groups[] = '(' . implode(' OR ', $clauses) . ')';
        }
        $where[] = '(' . implode(' OR ', $groups) . ')';

        $whereSql = implode(' AND ', $where);
        $total    = (int) Database::scalar('SELECT COUNT(*) FROM cases c WHERE ' . $whereSql, $params);

        $items = Database::select(
            'SELECT c.id, c.title, c.device, c.error_code, c.phenomenon, c.solution, c.views,
                    c.published_at, c.is_top, c.doc_type, c.process_id,
                    cat.name AS category_name, p.name AS process_name
             FROM cases c
             LEFT JOIN categories cat ON cat.id = c.category_id
             LEFT JOIN processes p ON p.id = c.process_id
             WHERE ' . $whereSql . '
             ORDER BY c.is_top DESC, c.views DESC, c.published_at DESC, c.id DESC
             LIMIT ' . (int) $limit,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 把用户输入拆成若干有意义的检索片段（低精度回退的前置处理）。
     *
     * 【为什么要拆而不是直接拿原词搜】
     * 用户输入常常是「扫码枪打印机 MSQ-200 报 ERROR 3」这种混合串：
     * 关键词（扫码枪）+ 设备型号（MSQ-200）+ 错误码（ERROR 3）混在一起。
     * 整串匹配等于什么都搜不到。但其中的「扫码枪」才是他真正想找的。
     *
     * 【拆分规则】
     * 1) 先把「字母/数字 + 空格 + 数字」的组合视为不可拆的整体 ——
     *    ERROR 3、Q/N1 这类错误码拆开后毫无意义，反而丢失信息
     * 2) 按空白与常见分隔符切开
     * 3) 剔除纯噪声：单字符、纯标点、纯数字（数字很少单独构成检索意图）
     * 4) 剔除型号类片段：不含中文、且含数字的组合（MSQ-200 / RS-15 / 8Q-3）——
     *    这类串几乎不可能是检索意图，留着反而把结果限制死
     * 5) 剩余片段按长度倒序（长片段信息量大，优先展示）
     *
     * @return array<int,string> 去重后的片段，长度不超过 6 个
     */
    private static function splitKeyword(string $keyword): array
    {
        // 归一化空白（含全角空格）
        $normalized = preg_replace('/[\s\x{3000}]+/u', ' ', trim($keyword));
        if ($normalized === null || $normalized === '') {
            return [];
        }

        /*
         * 第一步：保护「字母+数字」类不可拆组合。
         * 例：ERROR 3 / Q/N1-5B / EC 20 —— 把内部空格替换成占位符，
         * 使后续按空白切分时不会把它们拆散，最后再还原。
         *
         * 【占位符为什么用 U+0001 而不是 U+001F】
         * U+001F（ASCII 31，单元分隔符）虽然极少出现在文本里，但它**属于正则的 \s 类**
         * —— 会被随后的按空白切分当成空白给切开，等于没保护。
         * U+0001 不在 \s 中，是控制字符，正常输入不会出现，是安全的占位符。
         */
        $PLACEHOLDER = "\x01";   // SOH，控制字符，非 \s，正常文本不会出现
        $protected = preg_replace(
            '/([A-Za-z]{2,}(?:[\/\-][A-Za-z0-9]+)*)\s+(\d{1,5})(?![0-9])/u',
            '$1' . $PLACEHOLDER . '$2',
            $normalized
        );
        if ($protected === null) {
            $protected = $normalized;
        }

        $raw = preg_split('/[\s,;、|]+/u', $protected) ?: [];
        $out = [];

        foreach ($raw as $piece) {
            $piece = str_replace($PLACEHOLDER, ' ', trim($piece));
            $piece = trim($piece);
            if ($piece === '' || mb_strlen($piece, 'UTF-8') < 2) {
                continue;                       // 单字符与空片段无检索价值
            }
            // 纯标点
            if (preg_match('/^[\W_]+/u', $piece)) {
                continue;
            }
            // 纯数字：单独出现时几乎不是检索意图（已在上一步并入错误码组合）
            if (preg_match('/^\d+$/', $piece)) {
                continue;
            }
            /*
             * 型号类片段（含斜杠或连字符，且不含中文）：MSQ-200 / RS-15 / 8Q-3。
             *
             * 【为什么用「含 / 或 -」而不是「含数字」来判定】
             * 错误码与型号在形态上几乎一样，唯一稳定的区别是分隔符：
             *   型号 → MSQ-200、RS-15、Q/N1-5B   （有连字符或斜杠）
             *   错误码 → ERROR 3、EC 20、E1024     （纯字母数字，靠空格或直接连写）
             * 若按「含数字」判定，ERROR 3 / EC 20 会被当型号丢掉 ——
             * 而错误码恰恰是现场最常搜的东西，丢它的代价远大于多留一个型号。
             */
            if (!preg_match('/[\x{4e00}-\x{9fa5}]/u', $piece)
                && preg_match('/[A-Za-z0-9][\/\-][A-Za-z0-9]/', $piece)) {
                continue;
            }
            $out[$piece] = $piece;              // 关联数组天然去重
        }

        if ($out === []) {
            // 全部被判为噪声时（例如只输入了 MSQ-200），退回原词，
            // 让调用方至少还能尝试一次匹配，而不是直接放弃。
            $fallback = trim($keyword);
            return mb_strlen($fallback, 'UTF-8') >= 2 ? [$fallback] : [];
        }

        $out = array_keys($out);
        usort($out, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        return array_slice($out, 0, 6);
    }

    /**
     * 检索联想：优先取历史高频关键词，其次匹配案例标题。
     *
     * @return array<int,string>
     */
    public static function suggestions(string $keyword, int $limit = 8): array
    {
        $limit = min(20, max(1, $limit));
        if ($keyword === '') {
            $rows = Database::select(
                'SELECT keyword FROM search_logs WHERE result_count > 0
                 GROUP BY keyword ORDER BY COUNT(*) DESC LIMIT ' . $limit
            );
            return array_map(static function (array $row): string {
                return (string) $row['keyword'];
            }, $rows);
        }

        // 拼音首字母联想：输入 smq 直接提示「扫码枪」等中文术语，点击即检索
        $pinyinTerms = PinyinTerm::matchAbbr($keyword, 4);

        $rows = Database::select(
            'SELECT title FROM cases
             WHERE status = \'published\' AND deleted_at IS NULL AND title LIKE :kw
             ORDER BY views DESC LIMIT ' . $limit,
            ['kw' => '%' . self::escapeLike($keyword) . '%']
        );

        $result = [];
        foreach ($pinyinTerms as $term) {
            $result[] = $term;
        }
        foreach ($rows as $row) {
            $title = (string) $row['title'];
            if (!in_array($title, $result, true)) {
                $result[] = $title;
            }
        }

        return array_slice($result, 0, $limit);
    }

    /**
     * 前台详情（仅已发布案例可访问）。
     *
     * @return array<string,mixed>|null
     */
    public static function findPublished(int $id): ?array
    {
        $sql = 'SELECT c.*, cat.name AS category_name, p.name AS process_name, po.name AS position_name
                FROM cases c
                LEFT JOIN categories cat ON cat.id = c.category_id
                LEFT JOIN processes p ON p.id = c.process_id
                LEFT JOIN positions po ON po.id = c.position_id
                WHERE c.id = :id AND c.status = \'published\' AND c.deleted_at IS NULL LIMIT 1';
        return Database::selectOne($sql, ['id' => $id]);
    }

    public static function incrementViews(int $id): void
    {
        Database::execute('UPDATE cases SET views = views + 1 WHERE id = :id', ['id' => $id]);
    }

    public static function incrementDownloads(int $id): void
    {
        Database::execute('UPDATE cases SET downloads = downloads + 1 WHERE id = :id', ['id' => $id]);
    }

    /**
     * 相邻案例（详情页上下篇）。
     *
     * @return array{prev:array<string,mixed>|null,next:array<string,mixed>|null}
     */
    public static function neighbours(int $id): array
    {
        $prev = Database::selectOne(
            'SELECT id, title FROM cases WHERE status = \'published\' AND deleted_at IS NULL AND id < :id
             ORDER BY id DESC LIMIT 1',
            ['id' => $id]
        );
        $next = Database::selectOne(
            'SELECT id, title FROM cases WHERE status = \'published\' AND deleted_at IS NULL AND id > :id
             ORDER BY id ASC LIMIT 1',
            ['id' => $id]
        );
        return ['prev' => $prev, 'next' => $next];
    }

    /**
     * 首页列表。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function featured(string $sort = 'latest', int $limit = 8, int $categoryId = 0): array
    {
        $limit = min(50, max(1, $limit));
        // 首页「最新故障案例」只展示故障案例，手册/版本更新日志走各自板块
        $where = ["c.status = 'published'", 'c.deleted_at IS NULL', "c.doc_type = 'case'"];
        $params = [];

        if ($categoryId > 0) {
            // PDO 原生预处理（EMULATE_PREPARES=false）下同一命名占位符不能复用，
            // 必须拆成两个参数，否则报 SQLSTATE[HY093] Invalid parameter number。
            $where[]             = '(c.category_id = :cid_self OR c.category_id IN (SELECT id FROM categories WHERE parent_id = :cid_child))';
            $params['cid_self']  = $categoryId;
            $params['cid_child'] = $categoryId;
        }

        $orderBy = $sort === 'hot'
            ? 'c.views DESC, c.published_at DESC'
            : 'c.published_at DESC, c.id DESC';

        $sql = 'SELECT c.id, c.title, c.device, c.error_code, c.phenomenon, c.solution, c.views,
                       c.published_at, c.updated_at, c.is_top, c.doc_type, c.process_id,
                       cat.name AS category_name, p.name AS process_name
                FROM cases c
                LEFT JOIN categories cat ON cat.id = c.category_id
                LEFT JOIN processes p ON p.id = c.process_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.is_top DESC, ' . $orderBy . '
                LIMIT ' . $limit;

        return Database::select($sql, $params);
    }

    /**
     * 详情页「相关案例」：同分类（含子分类）优先，稀疏时按工序补位。
     *
     * 【为什么不用 featured() 直接顶】
     * featured() 是首页/列表的通用查询（固定 doc_type='case'、单来源），
     * 详情页要三种补位，且手册页与案例页的最相关项不同：
     *   ① 同分类 + 同文档类型（案例页看案例、手册页看手册）——按热度；
     *   ② 案例页：同工序、不同分类补位——库里大量分类只有一两条，
     *      单来源列表常只剩一两条，补位后列表才有得看；
     *   ③ 手册/日志页：保留原有跨类型价值——同分类的故障案例
     *      （看报工手册的人，下一步往往是要查报工相关的故障）。
     * 原实现（CaseController 直接调 featured）只有第①种，且手册页排不到同分类手册。
     *
     * @param array<string,mixed> $case 当前行（需含 id/doc_type/category_id/process_id）
     * @return array<int,array<string,mixed>>
     */
    public static function relatedTo(array $case, int $limit = 5): array
    {
        $limit     = min(10, max(1, $limit));
        $id        = (int) ($case['id'] ?? 0);
        $docType   = in_array((string) ($case['doc_type'] ?? 'case'), ['case', 'manual', 'changelog'], true)
            ? (string) $case['doc_type']
            : 'case';
        $catId     = (int) ($case['category_id'] ?? 0);
        $processId = (int) ($case['process_id'] ?? 0);
        if ($id <= 0) {
            return [];
        }

        $cols = 'c.id, c.title, c.views, c.doc_type, c.process_id,
                 cat.name AS category_name, p.name AS process_name';
        $picked   = [];
        $pickedId = [];
        $take = static function (array $rows) use (&$picked, &$pickedId): void {
            foreach ($rows as $row) {
                $rid = (int) $row['id'];
                if (in_array($rid, $pickedId, true)) {
                    continue;
                }
                $picked[]   = $row;
                $pickedId[] = $rid;
            }
        };

        // ① 同分类（含子分类）+ 同文档类型，按热度
        if ($catId > 0) {
            $take(Database::select(
                'SELECT ' . $cols . ' FROM cases c
                 LEFT JOIN categories cat ON cat.id = c.category_id
                 LEFT JOIN processes p ON p.id = c.process_id
                 WHERE c.status = \'published\' AND c.deleted_at IS NULL
                   AND c.doc_type = :dt AND c.id <> :id
                   AND (c.category_id = :cid OR c.category_id IN (SELECT id FROM categories WHERE parent_id = :cid_child))
                 ORDER BY c.views DESC, c.published_at DESC
                 LIMIT ' . $limit,
                ['dt' => $docType, 'id' => $id, 'cid' => $catId, 'cid_child' => $catId]
            ));
        }

        // ② 案例页：同工序、不同分类补位（稀疏分类下保证列表有得看）
        if ($docType === 'case' && $processId > 0 && count($picked) < $limit) {
            $take(Database::select(
                'SELECT ' . $cols . ' FROM cases c
                 LEFT JOIN categories cat ON cat.id = c.category_id
                 LEFT JOIN processes p ON p.id = c.process_id
                 WHERE c.status = \'published\' AND c.deleted_at IS NULL
                   AND c.doc_type = \'case\' AND c.id <> :id2 AND c.process_id = :pid
                 ORDER BY c.views DESC, c.published_at DESC
                 LIMIT ' . $limit,
                ['id2' => $id, 'pid' => $processId]
            ));
        }

        // ③ 手册/日志页：同分类的故障案例（跨类型推荐，保留原行为）
        if ($docType !== 'case' && $catId > 0 && count($picked) < $limit) {
            $take(Database::select(
                'SELECT ' . $cols . ' FROM cases c
                 LEFT JOIN categories cat ON cat.id = c.category_id
                 LEFT JOIN processes p ON p.id = c.process_id
                 WHERE c.status = \'published\' AND c.deleted_at IS NULL
                   AND c.doc_type = \'case\' AND c.id <> :id3
                   AND (c.category_id = :cid3 OR c.category_id IN (SELECT id FROM categories WHERE parent_id = :cid3_child))
                 ORDER BY c.views DESC, c.published_at DESC
                 LIMIT ' . $limit,
                ['id3' => $id, 'cid3' => $catId, 'cid3_child' => $catId]
            ));
        }

        return array_slice($picked, 0, $limit);
    }

    public static function latestManuals(?int $limit = null): array
    {
        $sql = 'SELECT c.id, c.title, c.views, c.published_at, c.updated_at, c.doc_type, c.position_id,
                       pos.name AS position_name
                FROM cases c
                LEFT JOIN positions pos ON pos.id = c.position_id
                WHERE c.status = \'published\' AND c.deleted_at IS NULL AND c.doc_type = \'manual\'
                ORDER BY c.published_at DESC, c.id DESC';
        if ($limit !== null) {
            $limit = min(50, max(1, $limit));
            $sql .= ' LIMIT ' . $limit;
        }
        return Database::select($sql, []);
    }

    // =========================================================
    // 版本记录
    // =========================================================

    /**
     * 写入一条版本快照。
     */
    public static function snapshot(int $caseId, array $case, int $editorId, string $editorName, string $note): void
    {
        Database::insert('case_versions', [
            'case_id'     => $caseId,
            'version_no'  => (int) ($case['version_no'] ?? 1),
            'snapshot'    => json_encode([
                'title'       => $case['title'] ?? '',
                'category_id' => $case['category_id'] ?? 0,
                'device'      => $case['device'] ?? '',
                'error_code'  => $case['error_code'] ?? '',
                'phenomenon'  => $case['phenomenon'] ?? '',
                'cause'       => $case['cause'] ?? '',
                'solution'    => $case['solution'] ?? '',
                'prevention'  => $case['prevention'] ?? '',
                'handler'     => $case['handler'] ?? '',
                'handled_at'  => $case['handled_at'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'change_note' => mb_substr($note, 0, 255, 'UTF-8'),
            'editor_id'   => $editorId,
            'editor_name' => $editorName,
            'created_at'  => now(),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function versions(int $caseId): array
    {
        return Database::select(
            'SELECT * FROM case_versions WHERE case_id = :id ORDER BY version_no DESC, id DESC',
            ['id' => $caseId]
        );
    }

    // =========================================================
    // 回收站
    // =========================================================

    public static function moveToTrash(int $id): int
    {
        return Database::execute(
            'UPDATE cases SET deleted_at = :t WHERE id = :id AND deleted_at IS NULL',
            ['t' => now(), 'id' => $id]
        );
    }

    public static function restore(int $id): int
    {
        return Database::execute('UPDATE cases SET deleted_at = NULL WHERE id = :id', ['id' => $id]);
    }

    /**
     * 彻底删除：物理删除案例、版本、附件记录。
     */
    public static function forceDelete(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            Database::execute('DELETE FROM case_versions WHERE case_id = :id', ['id' => $id]);
            Database::execute('DELETE FROM attachments WHERE case_id = :id', ['id' => $id]);
            Database::execute('DELETE FROM cases WHERE id = :id', ['id' => $id]);
        });
    }

    public static function trashCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM cases WHERE deleted_at IS NOT NULL');
    }

    // =========================================================
    // 统计看板（需求 4.5 数据统计与看板模块）
    // =========================================================

    /**
     * @return array<string,int>
     */
    public static function statusCounts(): array
    {
        $rows   = Database::select(
            'SELECT status, COUNT(*) AS total FROM cases WHERE deleted_at IS NULL GROUP BY status'
        );
        $result = array_fill_keys(array_keys(case_status_map()), 0);
        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }
        $result['total'] = array_sum($result);
        return $result;
    }

    /**
     * 各分类案例数量。
     *
     * @return array<int,array{name:string,count:int}>
     */
    public static function countByCategory(): array
    {
        $rows = Database::select(
            'SELECT COALESCE(cat.name, \'未分类\') AS name, COUNT(*) AS total
             FROM cases c
             LEFT JOIN categories cat ON cat.id = c.category_id
             WHERE c.deleted_at IS NULL
             GROUP BY cat.name
             ORDER BY total DESC'
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['name' => (string) $row['name'], 'count' => (int) $row['total']];
        }
        return $result;
    }

    public static function countThisMonth(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND created_at >= :start',
            ['start' => date('Y-m-01 00:00:00')]
        );
    }

    /**
     * 高浏览故障案例。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function topViewed(int $limit = 10): array
    {
        $limit = min(50, max(1, $limit));
        return Database::select(
            'SELECT c.id, c.title, c.views, cat.name AS category_name
             FROM cases c
             LEFT JOIN categories cat ON cat.id = c.category_id
             WHERE c.deleted_at IS NULL AND c.status = \'published\'
             ORDER BY c.views DESC LIMIT ' . $limit
        );
    }

    /**
     * 本周最多浏览（按 access_logs 浏览日志聚合，周一 00:00 起算）。
     *
     * 说明：cases.views 只累计总数，无法区分时间窗口，故用访问日志聚合。
     * 子查询先算本周次数，外层再关联案例，避免 ONLY_FULL_GROUP_BY 报错。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function weeklyTop(int $limit = 5): array
    {
        $limit = min(20, max(1, $limit));
        $since = date('Y-m-d 00:00:00', strtotime('monday this week'));

        $sql = 'SELECT c.id, c.title, c.device, c.phenomenon, c.solution, c.views,
                       c.published_at, c.created_at, c.is_top, c.doc_type, c.process_id,
                       cat.name AS category_name, p.name AS process_name, t.cnt AS week_views
                FROM (
                    SELECT a.case_id, COUNT(*) AS cnt
                    FROM access_logs a
                    JOIN cases k ON k.id = a.case_id
                    WHERE a.action = :act AND a.created_at >= :since
                      AND k.status = \'published\' AND k.deleted_at IS NULL
                      AND k.doc_type = \'case\'
                    GROUP BY a.case_id
                    ORDER BY cnt DESC
                    LIMIT 50
                ) t
                JOIN cases c ON c.id = t.case_id
                LEFT JOIN categories cat ON cat.id = c.category_id
                LEFT JOIN processes p ON p.id = c.process_id
                ORDER BY t.cnt DESC, c.views DESC
                LIMIT ' . $limit;

        return Database::select($sql, ['act' => 'view', 'since' => $since]);
    }

    /**
     * 热门检索词（需求 4.5 检索数据统计）。
     *
     * @return array<int,array{keyword:string,times:int,no_result:int}>
     */
    public static function topKeywords(int $limit = 10): array
    {
        $limit = min(50, max(1, $limit));
        $rows  = Database::select(
            'SELECT keyword,
                    COUNT(*) AS times,
                    SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS no_result
             FROM search_logs
             GROUP BY keyword
             ORDER BY times DESC
             LIMIT ' . $limit
        );
        // 线缆专属改版：工序类检索词优先展示（贴合按工序查问题的使用习惯）
        $processNames = array_map('strval', array_column(Process::enabled(), 'name'));
        $result = [];
        foreach ($rows as $row) {
            $keyword = (string) $row['keyword'];
            $result[] = [
                'keyword'   => $keyword,
                'times'     => (int) $row['times'],
                'no_result' => (int) $row['no_result'],
            ];
        }

        if ($processNames !== [] && $result !== []) {
            $isProcessWord = static function (string $keyword) use ($processNames): bool {
                foreach ($processNames as $name) {
                    if ($name !== '' && mb_stripos($keyword, $name) !== false) {
                        return true;
                    }
                }
                return false;
            };
            usort($result, static function (array $a, array $b) use ($isProcessWord): int {
                $pa = $isProcessWord($a['keyword']) ? 0 : 1;
                $pb = $isProcessWord($b['keyword']) ? 0 : 1;
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }
                return $b['times'] <=> $a['times'];
            });
            $result = array_slice($result, 0, $limit);
        }

        return $result;
    }

    /**
     * 工序维度文档统计（线缆专属改版：看板主统计）。
     *
     * @return array<int,array{name:string,count:int,process_id:int}>
     */
    public static function countByProcess(): array
    {
        $rows = Database::select(
            "SELECT COALESCE(p.name, '未绑定工序') AS name,
                    COALESCE(c.process_id, 0) AS process_id,
                    COUNT(*) AS total
             FROM cases c
             LEFT JOIN processes p ON p.id = c.process_id
             WHERE c.deleted_at IS NULL
             GROUP BY p.name, c.process_id
             ORDER BY total DESC"
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'name'       => (string) $row['name'],
                'count'      => (int) $row['total'],
                'process_id' => (int) $row['process_id'],
            ];
        }
        return $result;
    }

    /**
     * 指定工序内各故障类型分布（看板下钻）。
     *
     * @return array<int,array{name:string,count:int}>
     */
    public static function countByCategoryForProcess(int $processId): array
    {
        $rows = Database::select(
            "SELECT COALESCE(cat.name, '未分类') AS name, COUNT(*) AS total
             FROM cases c
             LEFT JOIN categories cat ON cat.id = c.category_id
             WHERE c.deleted_at IS NULL AND c.process_id = :pid
             GROUP BY cat.name
             ORDER BY total DESC",
            ['pid' => $processId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['name' => (string) $row['name'], 'count' => (int) $row['total']];
        }
        return $result;
    }

    /**
     * 无结果检索词（用于反哺案例补充）。
     *
     * @return array<int,array{keyword:string,times:int}>
     */
    public static function noResultKeywords(int $limit = 10): array
    {
        $limit = min(50, max(1, $limit));
        $rows  = Database::select(
            'SELECT keyword, COUNT(*) AS times FROM search_logs
             WHERE result_count = 0
             GROUP BY keyword ORDER BY times DESC LIMIT ' . $limit
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['keyword' => (string) $row['keyword'], 'times' => (int) $row['times']];
        }
        return $result;
    }

    /**
     * 近 N 天新增案例趋势。
     *
     * @return array<int,array{date:string,count:int}>
     */
    public static function dailyTrend(int $days = 14): array
    {
        $days  = min(90, max(7, $days));
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $rows = Database::select(
            'SELECT DATE(created_at) AS d, COUNT(*) AS total FROM cases
             WHERE deleted_at IS NULL AND created_at >= :start
             GROUP BY DATE(created_at)',
            ['start' => $start . ' 00:00:00']
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['d']] = (int) $row['total'];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime('-' . $i . ' days'));
            $result[] = ['date' => $date, 'count' => $map[$date] ?? 0];
        }
        return $result;
    }

    /**
     * 处理超期案例：published 且 expired_at 已过 -> expired。
     *
     * @return int 受影响条数
     */
    public static function expireOverdue(): int
    {
        return Database::execute(
            'UPDATE cases SET status = \'expired\'
             WHERE status = \'published\' AND deleted_at IS NULL
               AND expired_at IS NOT NULL AND expired_at < :now',
            ['now' => now()]
        );
    }

    /**
     * 新增趋势的汇总摘要（2026-10-18）。
     *
     * 【为什么需要它，光有柱状图不够】
     * 实测看板的 14 天趋势图：14 根柱子里 10 根是 0，柱子全是 2px 最小高度，
     * 视觉上就是一片平坦的线 —— 因为 80 条内容里 74 条集中在 9 月录入，
     * 用 14 天窗口看当下，必然「全是 0」。
     * 柱状图回答「每天多少」，但回答不了「最近到底还在不在录」。
     * 这个摘要给出：窗口内总量、活跃天数（有新增的天数）、
     * 日均、与上一个等长窗口的环比 —— 一眼看出是「正常水位」还是「真的衰退」。
     *
     * @param array<int,array{date:string,count:int}> $trend dailyTrend() 的返回值
     * @return array{total:int,activeDays:int,avg:float,prevTotal:int,delta:int,peak:int,peakDate:string}
     */
    public static function trendSummary(array $trend): array
    {
        $total = 0;
        $activeDays = 0;
        $peak = 0;
        $peakDate = '';
        foreach ($trend as $p) {
            $c = (int) $p['count'];
            $total += $c;
            if ($c > 0) {
                $activeDays++;
            }
            if ($c > $peak) {
                $peak = $c;
                $peakDate = (string) $p['date'];
            }
        }
        $days = max(1, count($trend));

        // 上一个等长窗口的总量（用于环比）
        $prevTotal = 0;
        if ($days > 0) {
            $end = date('Y-m-d', strtotime('-' . $days . ' days'));
            $start = date('Y-m-d', strtotime('-' . ($days * 2 - 1) . ' days'));
            $prevTotal = (int) Database::scalar(
                'SELECT COUNT(*) FROM cases
                 WHERE deleted_at IS NULL AND created_at >= :s AND created_at < :e',
                ['s' => $start . ' 00:00:00', 'e' => $end . ' 00:00:00']
            );
        }

        return [
            'total'     => $total,
            'activeDays'=> $activeDays,
            'avg'       => round($total / $days, 2),
            'prevTotal' => $prevTotal,
            'delta'     => $total - $prevTotal,
            'peak'      => $peak,
            'peakDate'  => $peakDate,
        ];
    }

    /**
     * 已发布但零浏览的案例数（内容沉底指标，2026-10-18）。
     *
     * 【为什么要单独看这个】
     * 实测 80 条内容里有 17 条 published 且 views=0（21%）——
     * 写了、审了、发了，但没人看过。这些内容的曝光完全依赖用户主动搜索，
     * 搜不到就永远沉底。看板只有「已发布数」没有「被看过数」，
     * 会把「发布了」误当成「用起来了」。
     */
    /**
     * 「发布后无人看」条数。
     *
     * $authorId > 0 时只统计该作者负责的内容 —— 与后台案例列表的
     * 权限口径（运维管理员只看自己创建的）保持一致。
     * 否则非超管在看板上看到全站数字，点进去却只剩自己那几条，数字与明细对不上。
     */
    public static function zeroViewCount(int $authorId = 0): int
    {
        $sql    = "SELECT COUNT(*) FROM cases
                   WHERE deleted_at IS NULL AND status = 'published' AND views = 0";
        $params = [];
        if ($authorId > 0) {
            $sql .= ' AND author_id = :zv_author';
            $params['zv_author'] = $authorId;
        }
        return (int) Database::scalar($sql, $params);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
