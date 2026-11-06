<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 汉字拼音首字母工具（检索增强 · 拼音联想）。
 *
 * 依赖 PHP intl 扩展的 Transliterator（Han-Latin）。服务器上已实测可用：
 *   Transliterator::create('Han-Latin; Lower; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC')
 *       ->transliterate('扫码枪打印机') === 'sao ma qiang da yin ji'
 * 若运行环境缺少 intl，available() 返回 false，拼音联想自动降级关闭，
 * 同义词、标签等其它检索能力不受影响。
 *
 * ©Yangzengrong 版权所有
 */
class Pinyin
{
    /** 转换规则链：汉字转拼音 → 小写 → 去声调符号 → 重组 */
    private const RULE = 'Han-Latin; Lower; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC';

    /** 汉字区间 */
    private const HAN_PATTERN = '/[\x{4e00}-\x{9fa5}]/u';

    /** @var \Transliterator|null|false 运行时缓存；false 表示不可用 */
    private static $transliterator = null;

    /**
     * 当前环境是否支持汉字转拼音。
     */
    public static function available(): bool
    {
        return self::instance() instanceof \Transliterator;
    }

    /**
     * 是否全为汉字（含空串判断由调用方负责）。
     */
    public static function isHanAll(string $text): bool
    {
        return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $text) === 1;
    }

    /**
     * 逐字拼音：「扫码枪」=> ['sao','ma','qiang']（与汉字一一对应）。
     *
     * 仅接受纯汉字串；转换结果字数不匹配时返回空数组，
     * 宁可放弃该词条也不产生错误联想。
     *
     * @return array<int,string>
     */
    public static function syllables(string $text): array
    {
        $transliterator = self::instance();
        if (!$transliterator instanceof \Transliterator || $text === '' || !self::isHanAll($text)) {
            return [];
        }

        $converted = $transliterator->transliterate($text);
        if (!is_string($converted) || $converted === '') {
            return [];
        }

        $parts = preg_split('/[^a-z]+/', $converted, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($parts) === count($chars) ? $parts : [];
    }

    /**
     * 首字母串：汉字取拼音首字母，字母数字原样保留小写，其余忽略。
     *
     *   abbr('扫码枪。ABC-1') === 'smqabc1'
     *
     * @return string
     */
    public static function abbr(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $segments = [];
        if (preg_match_all('/[\x{4e00}-\x{9fa5}]+|[a-zA-Z0-9]+/u', $text, $segments) === 0) {
            return '';
        }

        $out = '';
        foreach ($segments[0] as $segment) {
            if (preg_match(self::HAN_PATTERN, $segment) === 1) {
                foreach (self::syllables($segment) as $syllable) {
                    if ($syllable !== '') {
                        $out .= $syllable[0];
                    }
                }
                continue;
            }
            $out .= strtolower($segment);
        }

        return $out;
    }

    /**
     * 取 Transliterator 实例（失败返回 false / null）。
     *
     * @return \Transliterator|false|null
     */
    private static function instance()
    {
        if (self::$transliterator !== null) {
            return self::$transliterator;
        }

        if (!class_exists('\Transliterator')) {
            self::$transliterator = false;
            return false;
        }

        try {
            $transliterator = \Transliterator::create(self::RULE);
        } catch (\Throwable $e) {
            $transliterator = false;
        }

        self::$transliterator = $transliterator instanceof \Transliterator ? $transliterator : false;

        return self::$transliterator;
    }
}
