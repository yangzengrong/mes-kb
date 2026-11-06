<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 检索词切分：给「整句粘贴搜不到」提供降级候选词。
 *
 * 背景：案例检索是整串 LIKE。用户把报错原文整句粘进搜索框
 * （例如「扫码枪 MSQ-200 故障」「工单手册」）时，库里没有哪个字段含这串完整文字，
 * 结果必然是 0 条——可拆开看，「扫码枪」有 2 条、「工单」有 26 条，内容其实存在。
 *
 * 因此整串 0 命中时，按下列顺序生成候选词（越靠前越具体）：
 *   ① 显式分隔符切分：空格 / 逗号 / 顿号 / 分号 / 斜杠 / 竖线
 *   ② 中英边界切分：「扫码枪MSQ200」→「扫码枪」+「MSQ200」
 *   ③ 全汉字长片段补子串：「工单手册」整体无结果，但子串「工单」有 26 条
 *
 * 候选词交给控制器逐个试算命中数，取「有结果且命中最少」的那个（最具体），
 * 避免直接 OR 全部词带来的噪声（搜「…故障」会命中几十条泛结果）。
 *
 * 只在整串 0 命中时才走到这里，平常不产生任何额外查询。
 *
 * ©Yangzengrong 版权所有
 */
final class QueryTerms
{
    /** 候选词上限（每个候选一次 COUNT，控制在十次以内） */
    private const MAX_TERMS = 12;

    /** 补子串时的最大子串长度（长词优先，4 字足够覆盖设备名 + 术语） */
    private const MAX_SUBSTR = 4;

    /**
     * 虚词：单独出现没有检索意义，参与切分只会制造噪声。
     * 只放真正的虚词，像「故障 / 异常 / 同步」这类实词必须保留。
     */
    private const STOPWORDS = [
        '的', '了', '是', '在', '和', '与', '或', '及', '吗', '呢', '吧', '啊', '哦',
        '请', '我', '你', '他', '这', '那', '有', '个', '怎么', '怎样', '如何',
        '为什么', '什么', '一下', '不了', '没有',
    ];

    /**
     * 降级候选词（已去重、已过滤虚词与单字，按「具体程度」降序）。
     *
     * @return array<int,string>
     */
    public static function candidates(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        // ① + ②：显式分隔符切分后，再按中英边界拆成纯汉字片段 / 字母数字片段
        $segments = [];
        foreach (preg_split('/[\s,，、;；\/|+]+/u', $keyword) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match_all('/[\x{4e00}-\x{9fa5}]+|[a-zA-Z0-9][a-zA-Z0-9_\-\.]*/u', $part, $m) > 0) {
                foreach ($m[0] as $segment) {
                    $segments[] = $segment;
                }
            }
        }
        if ($segments === []) {
            return [];
        }

        $terms = [];
        // 先放整体片段：整体比子串更具体，且截断时优先保住它们
        foreach ($segments as $segment) {
            self::push($terms, $segment);
        }

        // ③ 全汉字长片段：按长度降序补子串（4 字 → 3 字 → 2 字）
        foreach ($segments as $segment) {
            $length = mb_strlen($segment, 'UTF-8');
            if ($length < 3 || !self::isHanAll($segment)) {
                continue;
            }
            $chars = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            for ($size = min(self::MAX_SUBSTR, $length - 1); $size >= 2; $size--) {
                for ($i = 0; $i + $size <= $length; $i++) {
                    self::push($terms, implode('', array_slice($chars, $i, $size)));
                }
            }
        }

        return array_values($terms);
    }

    /**
     * 加入候选词（去重 / 过滤虚词与单字 / 限流）。
     *
     * @param array<string,string> $terms
     */
    private static function push(array &$terms, string $term): void
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term, 'UTF-8') < 2) {
            return;
        }
        if (in_array($term, self::STOPWORDS, true)) {
            return;
        }
        if (isset($terms[$term]) || count($terms) >= self::MAX_TERMS) {
            return;
        }
        $terms[$term] = $term;
    }

    /**
     * 是否全为汉字（字母数字片段不参与子串切分，避免把型号 MSQ-200 拆碎）。
     */
    private static function isHanAll(string $text): bool
    {
        return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $text) === 1;
    }
}
