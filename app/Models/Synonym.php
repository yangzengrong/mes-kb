<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Setting;

/**
 * 检索同义词（后台「系统配置 → 检索增强 · 同义词」维护）。
 *
 * 存储格式：settings.search.synonyms，每行一组，主词与别名用 = 分隔，别名用中英文逗号分隔：
 *   扫码枪=扫描枪,扫码器,扫枪
 *   工控机=工业电脑,IPC
 * 以 # 开头的行视为注释。
 *
 * 命中规则：搜索词中出现组内任一词，即把同组其它词一并纳入检索条件，
 * 使用户搜「扫描枪」也能命中写着「扫码枪」的案例。
 */
class Synonym
{
    /** 单词长度上限（防止异常长词拖慢 LIKE） */
    private const MAX_TERM_LEN = 20;
    /** 最多解析组数 */
    private const MAX_GROUPS = 100;
    /** 单次检索最多扩展的词数（避免 SQL 条件膨胀） */
    private const MAX_EXPAND = 4;

    /** @var array<int,array<int,string>>|null 请求内缓存 */
    private static $groups = null;

    /**
     * 解析后的同义词组（每组至少 2 个词）。
     *
     * @return array<int,array<int,string>>
     */
    public static function groups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $raw    = (string) Setting::get('search.synonyms', '');
        $groups = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $line = str_replace('＝', '=', $line);
            if (strpos($line, '=') === false) {
                continue;
            }

            [$head, $tail] = explode('=', $line, 2);
            $members = [];
            $candidates = array_merge([$head], preg_split('/[，,、;；|]+/u', $tail) ?: []);
            foreach ($candidates as $term) {
                $term = trim((string) $term);
                if ($term === '' || mb_strlen($term, 'UTF-8') > self::MAX_TERM_LEN) {
                    continue;
                }
                $members[$term] = $term;
            }

            if (count($members) < 2) {
                continue;
            }

            $groups[] = array_values($members);
            if (count($groups) >= self::MAX_GROUPS) {
                break;
            }
        }

        self::$groups = $groups;
        return $groups;
    }

    /**
     * 关键词扩展：原词在前，命中的同义词在后。
     *
     * @return array<int,string>
     */
    public static function expand(string $keyword): array
    {
        $terms = [$keyword];
        foreach (self::extraTerms($keyword) as $term) {
            $terms[] = $term;
        }
        return $terms;
    }

    /**
     * 命中的同义词（不含原词本身），用于前台「已同时检索」提示。
     *
     * @return array<int,string>
     */
    public static function extraTerms(string $keyword): array
    {
        if (trim($keyword) === '') {
            return [];
        }

        $extra = [];
        foreach (self::groups() as $group) {
            $hit = false;
            foreach ($group as $member) {
                // 注意：mb_stripos 第 3 参是整数偏移，不能传编码，否则 PHP 8 抛 TypeError
                if (mb_stripos($keyword, $member) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }

            foreach ($group as $member) {
                if (mb_stripos($keyword, $member) !== false || in_array($member, $extra, true)) {
                    continue;
                }
                $extra[] = $member;
            }

            if (count($extra) >= self::MAX_EXPAND) {
                break;
            }
        }

        return array_slice(array_values(array_unique($extra)), 0, self::MAX_EXPAND);
    }

    /**
     * 同义词组数（后台概览提示）。
     */
    public static function count(): int
    {
        return count(self::groups());
    }

    /**
     * 供文档/页面展示的默认示例（未配置时的占位提示）。
     */
    public static function sample(): string
    {
        return "扫码枪=扫描枪,扫码器,扫枪\n打印机=条码机,打码机\n工控机=工业电脑,IPC";
    }
}
