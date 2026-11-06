<?php

declare(strict_types=1);

namespace App\Models;

/**
 * 案例有效性反馈（断点D）。
 *
 * 【为什么需要「不点也算没帮到」】
 * 19/74 案例从未被浏览（26%），管理员无法区分两种情况：
 *   · 写得差没人用       → 该重写或下架
 *   · 写得好但没被搜到   → 该改检索策略
 * 在数据上两者完全一样（views=0），所以「没人点」本身就是强信号 ——
 * 看了都觉得没用，比没人看更严重。
 *
 * 【一人一票的去重方式】
 * 前台浏览案例不强制登录（反馈功能也支持匿名），所以无法用账号去重。
 * 这里用「浏览器指纹哈希」：只用于避免同一个人连点把比例推歪，
 * **不存原始 IP、不做个人识别**，哈希后不可逆推。
 *
 * 【规则：一案例一条】
 * 唯一索引 (case_id, voter_key) + upsert —— 同一人对同一条只保留最后一次反馈。
 * 否则反复点击会让比例越来越极端（点 10 次 ✓ 就变成 100% 有效）。
 */
final class CaseEffectiveness
{
    /**
     * 生成投票人标识。
     *
     * 只取 User-Agent 与 Accept-Language 做哈希 —— 不含 IP、不含 Cookie。
     * 同一台电脑同一浏览器会得到相同 key，因此能达到「同一人不重复计数」；
     * 不同同事的 UA/Lang 组合几乎必然不同，误判成同人的概率极低。
     * 即便误判（极端情况下两台机器配置一致），后果也只是少一票，不影响数据完整性。
     */
    public static function voterKey(): string
    {
        $ua   = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $lang = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $seed = $ua . '|' . $lang;
        return substr(hash('sha256', $seed), 0, 32);
    }

    /**
     * 记录一次反馈（同一人对同一条覆盖写）。
     *
     * @return bool 是否写入成功
     */
    public static function record(int $caseId, bool $helpful, string $note = ''): bool
    {
        if ($caseId <= 0) {
            return false;
        }
        $note = trim($note);
        if (mb_strlen($note, 'UTF-8') > 120) {
            $note = mb_substr($note, 0, 120, 'UTF-8');
        }

        // 案例不存在时不写入（避免脏数据）
        $exists = \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM cases WHERE id = :id AND deleted_at IS NULL',
            ['id' => $caseId]
        );
        if ((int) $exists === 0) {
            return false;
        }

        $key = self::voterKey();
        if ($key === '') {
            return false;
        }

        // upsert：靠唯一索引 (case_id, voter_key) 覆盖写
        \App\Core\Database::execute(
            'INSERT INTO case_effectiveness (case_id, voter_key, helpful, note)
             VALUES (:cid, :vkey, :helpful, :note)
             ON DUPLICATE KEY UPDATE helpful = VALUES(helpful), note = VALUES(note)',
            [
                'cid'     => $caseId,
                'vkey'    => $key,
                'helpful' => $helpful ? 1 : 0,
                'note'    => $note,
            ]
        );

        return true;
    }

    /**
     * 单条案例的反馈统计。
     *
     * @return array{helpful:int,unhelpful:int,total:int,rate:int,myVote:int|null}
     *   rate  帮到率 0~100（total=0 时为 -1 表示无数据）
     *   myVote 当前访客的投票（null=没投过）
     */
    public static function statsFor(int $caseId): array
    {
        $row = \App\Core\Database::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END), 0) AS helpful,
                COALESCE(SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END), 0) AS unhelpful
             FROM case_effectiveness WHERE case_id = :cid',
            ['cid' => $caseId]
        );

        $helpful   = (int) ($row['helpful'] ?? 0);
        $unhelpful = (int) ($row['unhelpful'] ?? 0);
        $total     = $helpful + $unhelpful;

        $myVote = null;
        $key    = self::voterKey();
        if ($key !== '') {
            $mine = \App\Core\Database::scalar(
                'SELECT helpful FROM case_effectiveness WHERE case_id = :cid AND voter_key = :vkey',
                ['cid' => $caseId, 'vkey' => $key]
            );
            if ($mine !== null) {
                $myVote = (int) $mine === 1 ? true : false;
            }
        }

        return [
            'helpful'   => $helpful,
            'unhelpful' => $unhelpful,
            'total'     => $total,
            'rate'      => $total === 0 ? -1 : (int) round($helpful * 100 / $total),
            'myVote'    => $myVote,
        ];
    }

    /**
     * 后台用：批量取多条案例的反馈统计。
     *
     * 用一次 IN 查询取回，避免列表页 N+1 查询。
     *
     * @param  array<int,int> $caseIds
     * @return array<int,array{helpful:int,unhelpful:int,total:int,rate:int}>
     */
    public static function statsForMany(array $caseIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $caseIds))));
        if ($ids === []) {
            return [];
        }

        // 动态条件必须生成唯一占位符 —— ATTR_EMULATE_PREPARES=false 下同名不可复用
        $placeholders = [];
        $params       = [];
        foreach ($ids as $i => $id) {
            $key          = 'eff' . $i;
            $placeholders[] = ':' . $key;
            $params[$key]   = $id;
        }

        $rows = \App\Core\Database::select(
            'SELECT case_id,
                    COALESCE(SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END), 0) AS helpful,
                    COALESCE(SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END), 0) AS unhelpful
             FROM case_effectiveness
             WHERE case_id IN (' . implode(',', $placeholders) . ')
             GROUP BY case_id',
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $helpful   = (int) $r['helpful'];
            $unhelpful = (int) $r['unhelpful'];
            $total     = $helpful + $unhelpful;
            $out[(int) $r['case_id']] = [
                'helpful'   => $helpful,
                'unhelpful' => $unhelpful,
                'total'     => $total,
                'rate'      => $total === 0 ? -1 : (int) round($helpful * 100 / $total),
            ];
        }
        return $out;
    }

    /**
     * 后台「待复核」清单。
     *
     * 【判定规则 —— 刻意保守】
     * 只把「确实有信号」的挑出来，不追求覆盖：
     *   · 有反馈且帮到率 < 50%  → 内容可能不准确，用户明确说了没用
     *   · 有反馈且帮到率 = 0%   → 优先级最高
     *   · 浏览量高但零反馈      → 看了很多却没人投票，说明确实帮到（不必改）
     *
     * 「浏览量低且零反馈」的**不列进来** —— 那可能只是没人遇到这个故障，
     * 不是内容有问题。全列出来会让待复核清单变成噪音，管理员就会忽略它。
     *
     * @param  int $minViews 浏览量下限（低于此值不视为「看了很多却没用」）
     * @return array<int,array<string,mixed>>
     */
    public static function reviewQueue(int $limit = 50, int $minViews = 20): array
    {
        /**
         * 注意：表里只有 helpful 单列（1=帮到，0=没帮到），**没有 unhelpful 列**。
         * 「没帮到」的数量要靠聚合时按 case_id 分组统计 —— 直接写 e.unhelpful
         * 会报 Unknown column（这个坑踩过一次）。
         *
         * 所以这里用子查询做逐案例聚合，再 LEFT JOIN 回 cases。
         */
        return \App\Core\Database::select(
            'SELECT c.id, c.title, c.status, c.views, c.updated_at,
                    COALESCE(e.helpful, 0)   AS helpful,
                    COALESCE(e.unhelpful, 0) AS unhelpful,
                    (COALESCE(e.helpful, 0) + COALESCE(e.unhelpful, 0)) AS votes,
                    p.name AS process_name
             FROM cases c
             LEFT JOIN (
                 SELECT case_id,
                        COALESCE(SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END), 0) AS helpful,
                        COALESCE(SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END), 0) AS unhelpful
                 FROM case_effectiveness
                 GROUP BY case_id
             ) e ON e.case_id = c.id
             LEFT JOIN processes p ON p.id = c.process_id
             WHERE c.deleted_at IS NULL
               AND c.doc_type = \'case\'
               AND c.status = \'published\'
               AND (
                     -- 规则1：有人反馈过，但一半以上说没用
                     (COALESCE(e.helpful, 0) + COALESCE(e.unhelpful, 0)) > 0
                       AND COALESCE(e.helpful, 0) * 2 < (COALESCE(e.helpful, 0) + COALESCE(e.unhelpful, 0))
                     -- 规则2：很多人看过，却一个反馈都没有
                     OR (COALESCE(e.helpful, 0) + COALESCE(e.unhelpful, 0)) = 0 AND c.views >= :minv
                   )
             ORDER BY votes ASC, c.views DESC
             LIMIT ' . (int) $limit,
            ['minv' => $minViews]
        );
    }

    /**
     * 后台看板用：整体有效数据。
     *
     * @return array{published:int,withVotes:int,zeroUsefulRate:int,noVoteHighView:int}
     */
    public static function overview(int $minViews = 20): array
    {
        $row = \App\Core\Database::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL
                   AND doc_type = \'case\' AND status = \'published\') AS published,
                (SELECT COUNT(*) FROM cases c
                   WHERE c.deleted_at IS NULL AND c.doc_type = \'case\' AND c.status = \'published\'
                     AND EXISTS (SELECT 1 FROM case_effectiveness e WHERE e.case_id = c.id)) AS withVotes,
                (SELECT COUNT(*) FROM cases c
                   JOIN case_effectiveness e ON e.case_id = c.id
                   WHERE c.deleted_at IS NULL AND c.doc_type = \'case\' AND c.status = \'published\'
                     AND e.helpful = 0) AS zeroUsefulRate,
                (SELECT COUNT(*) FROM cases c
                   WHERE c.deleted_at IS NULL AND c.doc_type = \'case\' AND c.status = \'published\'
                     AND c.views >= :minv1
                     AND NOT EXISTS (SELECT 1 FROM case_effectiveness e WHERE e.case_id = c.id)) AS noVoteHighView',
            ['minv1' => $minViews]
        );

        return [
            'published'       => (int) ($row['published'] ?? 0),
            'withVotes'       => (int) ($row['withVotes'] ?? 0),
            'zeroUsefulRate'  => (int) ($row['zeroUsefulRate'] ?? 0),
            'noVoteHighView'  => (int) ($row['noVoteHighView'] ?? 0),
        ];
    }
}
