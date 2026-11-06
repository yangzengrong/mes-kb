<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\CaseTag;
use App\Core\Database;
use App\Core\Pinyin;

/**
 * 拼音联想词表（检索增强 · 拼音首字母）。
 *
 * 思路：案例标题 / 标签 / 设备名、MES 菜单名、工序与故障类型名构成术语库，
 * 预先转成「逐字拼音」索引。用户输入纯字母缩写（如 smq / dyj）时，
 * 在索引里滑动比对首字母组合，命中则返回对应中文词，
 * 由检索层当作附加关键词一并参与 LIKE 检索。
 *
 * 例：输入 smq → 命中「扫码枪」→ 等价于同时搜索「扫码枪」。
 *
 * 索引缓存在 storage/cache/pinyin_index.json（Web 不可访问，6 小时自动失效），
 * 后台保存案例/菜单时会主动 flush()，新词即时可搜。
 *
 * ©Yangzengrong 版权所有
 */
class PinyinTerm
{
    /** 缓存目录（相对 STORAGE_PATH） */
    private const CACHE_DIR = '/cache';
    /** 缓存文件名 */
    private const CACHE_FILE = 'pinyin_index.json';
    /** 缓存有效期（秒）：6 小时 */
    private const CACHE_TTL = 21600;
    /** 单次检索最多扩展的联想词 */
    private const MAX_EXPAND = 3;
    /** 索引词条上限（防止超大库拖慢建索引） */
    private const MAX_WORDS = 3000;
    /** 术语候选来源单次取数上限 */
    private const MAX_ROWS = 2000;

    /** @var array<int,array{c:array<int,string>,p:array<int,string>}>|null 请求内缓存 */
    private static $runtimeIndex = null;

    /**
     * 拼音联想是否可用（依赖 intl 扩展）。
     */
    public static function enabled(): bool
    {
        return Pinyin::available();
    }

    /**
     * 按拼音首字母缩写匹配中文词。
     *
     * 仅接受 2 - 8 位纯字母（纯数字交给普通检索），返回去重的中文词列表。
     *
     * @return array<int,string>
     */
    public static function matchAbbr(string $query, int $limit = self::MAX_EXPAND): array
    {
        if (!self::enabled()) {
            return [];
        }

        $query = strtolower(trim($query));
        if ($query === '' || preg_match('/^[a-z]{2,8}$/', $query) !== 1) {
            return [];
        }

        $limit = max(1, min(8, $limit));
        $needle = strlen($query);
        $hits   = [];

        foreach (self::index() as $item) {
            $count = count($item['p']);
            if ($count < $needle) {
                continue;
            }
            for ($offset = 0; $offset + $needle <= $count; $offset++) {
                $abbr = '';
                for ($step = 0; $step < $needle; $step++) {
                    $abbr .= $item['p'][$offset + $step][0];
                }
                if ($abbr !== $query) {
                    continue;
                }
                $word = implode('', array_slice($item['c'], $offset, $needle));
                // 至少两个汉字（UTF-8 下一个汉字 3 字节）
                if (mb_strlen($word, 'UTF-8') < 2) {
                    continue;
                }
                if (!isset($hits[$word])) {
                    $hits[$word] = true;
                }
                break;
            }
            if (count($hits) >= $limit + 5) {
                break;
            }
        }

        return array_slice(array_keys($hits), 0, $limit);
    }

    /**
     * 前台提示用：「缩写 → 中文词」对照。
     *
     * @return array<int,array{abbr:string,word:string}>
     */
    public static function hintPairs(string $keyword): array
    {
        $pairs = [];
        foreach (self::matchAbbr($keyword, self::MAX_EXPAND) as $word) {
            $pairs[] = ['abbr' => strtolower(trim($keyword)), 'word' => $word];
        }
        return $pairs;
    }

    /**
     * 清除索引缓存（后台录入新案例/菜单后调用，保证新词立即可搜）。
     */
    public static function flush(): void
    {
        self::$runtimeIndex = null;
        $file = self::cacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 术语索引：每项 ['c' => 逐字数组, 'p' => 逐字拼音数组]。
     *
     * @return array<int,array{c:array<int,string>,p:array<int,string>}>
     */
    public static function index(): array
    {
        if (self::$runtimeIndex !== null) {
            return self::$runtimeIndex;
        }
        if (!self::enabled()) {
            return [];
        }

        $cached = self::readCache();
        if ($cached !== null) {
            self::$runtimeIndex = $cached;
            return $cached;
        }

        $built = self::build();
        self::$runtimeIndex = $built;
        self::writeCache($built);

        return $built;
    }

    /**
     * 构建索引：从案例、菜单、工序、故障类型中提取中文词。
     *
     * @return array<int,array{c:array<int,string>,p:array<int,string>}>
     */
    private static function build(): array
    {
        $items = [];
        $seen  = [];

        foreach (self::phrases() as $phrase) {
            if (preg_match_all('/[\x{4e00}-\x{9fa5}]+/u', $phrase, $matches) === 0) {
                continue;
            }
            foreach ($matches[0] as $segment) {
                $length = mb_strlen($segment, 'UTF-8');
                if ($length < 2 || $length > 24 || isset($seen[$segment])) {
                    continue;
                }
                $syllables = Pinyin::syllables($segment);
                if (count($syllables) !== $length) {
                    continue;
                }
                $seen[$segment] = true;
                $items[] = [
                    'c' => preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    'p' => $syllables,
                ];
                if (count($items) >= self::MAX_WORDS) {
                    return $items;
                }
            }
        }

        return $items;
    }

    /**
     * 术语候选短语集合。
     *
     * @return array<int,string>
     */
    private static function phrases(): array
    {
        $phrases = [];

        try {
            $cases = Database::select(
                'SELECT title, tags, device FROM cases
                 WHERE status = \'published\' AND deleted_at IS NULL
                 ORDER BY id DESC LIMIT ' . self::MAX_ROWS
            );
            foreach ($cases as $case) {
                $phrases[] = (string) ($case['title'] ?? '');
                $phrases[] = (string) ($case['device'] ?? '');
                foreach (CaseTag::parse((string) ($case['tags'] ?? '')) as $tag) {
                    $phrases[] = $tag;
                }
            }

            foreach (Database::select(
                'SELECT DISTINCT menu_name AS word FROM mes_menus WHERE menu_name <> \'\' LIMIT ' . self::MAX_ROWS
            ) as $row) {
                $phrases[] = (string) $row['word'];
            }

            foreach (Database::select('SELECT name AS word FROM processes LIMIT 200') as $row) {
                $phrases[] = (string) $row['word'];
            }

            foreach (Database::select('SELECT name AS word FROM categories LIMIT 300') as $row) {
                $phrases[] = (string) $row['word'];
            }
        } catch (\Throwable $e) {
            // 表结构异常或库不可用时降级：拼音联想失效，不影响其它检索
        }

        return $phrases;
    }

    /**
     * 读取文件缓存（过期返回 null）。
     *
     * @return array<int,array{c:array<int,string>,p:array<int,string>}>|null
     */
    private static function readCache(): ?array
    {
        $file = self::cacheFile();
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        if ((time() - (int) @filemtime($file)) > self::CACHE_TTL) {
            return null;
        }

        $raw = (string) @file_get_contents($file);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || !isset($item['c'], $item['p']) || !is_array($item['c']) || !is_array($item['p'])) {
                continue;
            }
            if (count($item['c']) !== count($item['p'])) {
                continue;
            }
            $items[] = [
                'c' => array_values(array_map('strval', $item['c'])),
                'p' => array_values(array_map('strval', $item['p'])),
            ];
        }

        return $items;
    }

    /**
     * 写入文件缓存（失败静默：仅影响性能不影响功能）。
     *
     * @param array<int,array{c:array<int,string>,p:array<int,string>}> $items
     */
    private static function writeCache(array $items): void
    {
        $dir = STORAGE_PATH . self::CACHE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }

        $tmp  = self::cacheFile() . '.' . getmypid() . '.tmp';
        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, self::cacheFile());
    }

    /**
     * 缓存文件绝对路径。
     */
    private static function cacheFile(): string
    {
        return STORAGE_PATH . self::CACHE_DIR . '/' . self::CACHE_FILE;
    }
}
