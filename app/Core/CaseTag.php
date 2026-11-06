<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 案例标签解析器。
 *
 * 【为什么需要这个类】
 * 标签原本有 5 处各写各的解析逻辑，导致三个实际缺陷：
 *   1. 分隔符不统一 —— 写入端只认「,，;；」，漏了顿号「、」和斜杠「/」。
 *      库里 29 条标签有 14 条用顿号分隔，于是「MES、ERP、同步平台」被当成
 *      一个标签，按标签搜索「MES」命中 0 条（检索用 CONCAT LIKE 精确匹配）。
 *   2. 渲染端只认英文逗号 —— 前台 explode(',') 会把顿号串原样显示成
 *      一个超长标签，点进去搜不到。
 *   3. 没有「适用性」概念 —— MES 按线体区分系统，但标签里无处表达
 *      「这条案例适用于哪条线/机台/版本」，一线看到案例还得自己判断适不适用。
 *
 * 【标签的两种形态（本类的核心约定）】
 *   · 关键词标签：纯词，如「工艺参数」「串联机」，用于按标签检索同类内容
 *   · 适用性标签：带半角冒号，如「护套:8线」「机台:3#」「版本:V3.2」，
 *     第一段是维度名、冒号后是取值。前台会独立成组渲染（视觉优先级更高），
 *     表达「这条内容适用于什么环境」——这是 MES 场景下最常被问到的信息。
 *
 * 【为什么仍复用 tags 字段而不加新列】
 *   tags 是 VARCHAR(255) 且已有完整的前台渲染与检索通路。新增列意味着
 *   迁移历史数据 + 改 5 处读写 + 加索引，而带冒号即可无歧义地区分两类标签，
 *   历史数据还能从工序名自动推断初始值。零表结构变更是本项目的硬约束。
 *
 * 【存储格式约定】
 *   统一存为半角逗号分隔。写入前必过 normalize()，保证库里不再出现
 *   顿号、连续逗号、尾部逗号、单个空格标签等脏格式。
 */
final class CaseTag
{
    /** 分隔符：半角/全角逗号、分号、顿号、斜杠、竖线 —— 都视为标签边界。 */
    private const SPLIT_RE = '/[,，;；、|\/]+/u';

    /** 写入时统一使用的分隔符。 */
    public const SEPARATOR = ',';

    /** 单个标签最大长度（与历史写入规则保持一致）。 */
    private const MAX_LEN = 20;

    /** 单条内容最多保留的标签数。 */
    private const MAX_COUNT = 10;

    /**
     * 适用的标签前缀白名单（写入时提示用，实际不强制）。
     *
     * 【为什么刻意不含「工序」】
     * 工序是系统里已有的一等维度（cases.process_id + processes 表），
     * 前台导航、筛选、标签页全按它组织，案例详情页也已展示工序名。
     * 把工序名再写进 tags 标签是纯冗余 —— 而且 22/74 条案例绑的是「其它」，
     * 「适用范围：工序 其它」这种取值毫无信息量，白占位置还误导人以为
     * 适用范围已被认真标注过。
     *
     * 适用性标签的价值在于表达工序表**没有**的信息：具体线体、机台、版本。
     */
    public const SUGGESTED_DIMENSIONS = ['产线', '机台', '线体', '设备', '版本', '系统'];

    /**
     * 已知维度（用于前台分组时给出中文标签）。
     *
     * 只用于展示，解析时不依赖本表 —— 用户可自造维度（如「工单:R260142」），照样识别。
     */
    public const SCOPE_DIMENSIONS = [
        '产线'   => '产线',
        '机台'   => '机台',
        '设备'   => '设备',
        '线体'   => '线体',
        '版本'   => '版本',
        '系统'   => '系统',
        '工单'   => '工单',
        '岗位'   => '岗位',
        '工序'   => '工序',
        '适用'   => '适用范围',
    ];

    /**
     * 把任意来源的标签字符串切成规范标签数组。
     *
     * 【为什么前端和后端都必须走这个方法】
     * 库里存在历史脏数据（顿号分隔）。若渲染端自行 explode(',')，
     * 脏数据就会以超长标签的形式暴露给用户。读取端统一走这里，
     * 脏数据立刻被正确拆分，不需要等迁移脚本执行完才见效。
     *
     * @return array<int,string> 去重后的非空标签，保持首次出现顺序
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split(self::SPLIT_RE, $raw);
        if ($parts === false) {
            return [];
        }

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!self::isValid($part)) {
                continue;
            }
            $out[$part] = $part;               // 关联数组天然去重
        }

        return array_values($out);
    }

    /**
     * 把标签字符串归一化为「可直接写库」的规范格式。
     *
     * 写入端专用。会做长度截断、数量上限与空值清理，
     * 保证 tags 列里不再出现顿号、连续逗号、尾部逗号。
     */
    public static function normalize(?string $raw): string
    {
        $tags = self::parse($raw);
        if ($tags === []) {
            return '';
        }

        $tags = array_map(static function (string $tag): string {
            return mb_strlen($tag, 'UTF-8') > self::MAX_LEN
                ? mb_substr($tag, 0, self::MAX_LEN, 'UTF-8')
                : $tag;
        }, $tags);

        return implode(self::SEPARATOR, array_slice($tags, 0, self::MAX_COUNT));
    }

    /**
     * 拆出适用性标签（含半角冒号的那些）。
     *
     * 只认半角冒号 —— 中文冒号「：」在全角输入环境里极难与句内标点区分，
     * 放进来会造成误判。若历史数据里存在中文冒号，normalize() 时会被
     * 当作普通字符保留，不影响本方法的语义。
     *
     * @return array<int,array{dim:string,value:string,raw:string}>
     */
    public static function scopes(?string $raw): array
    {
        $out = [];
        foreach (self::parse($raw) as $tag) {
            $pos = strpos($tag, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $dim   = trim(substr($tag, 0, $pos));
            $value = trim(substr($tag, $pos + 1));
            // 冒号后必须有取值，否则不是有效的适用性标注
            if ($dim === '' || $value === '') {
                continue;
            }
            $out[] = ['dim' => $dim, 'value' => $value, 'raw' => $tag];
        }
        return $out;
    }

    /**
     * 拆出纯关键词标签（不含冒号的部分）。
     *
     * @return array<int,string>
     */
    public static function keywords(?string $raw): array
    {
        $out = [];
        foreach (self::parse($raw) as $tag) {
            $pos = strpos($tag, ':');
            if ($pos !== false && $pos > 0 && trim(substr($tag, $pos + 1)) !== '') {
                continue;                        // 是适用性标签，跳过
            }
            $out[] = $tag;
        }
        return $out;
    }

    /**
     * 判断某个标签是否合法。
     *
     * parse() 与 isValid() 都用这个判断，因为库里有历史脏数据，
     * 读取时若不过滤，脏标签会直接暴露到前台。
     *
     * 允许：纯关键词（「工艺参数」）、合规的适用性标注（「护套:8线」）
     * 拒绝：空串、纯标点、超过 20 字、以冒号开头或结尾（残缺的适用性标注）
     */
    public static function isValid(string $tag): bool
    {
        $tag = trim($tag);
        if ($tag === '' || mb_strlen($tag, 'UTF-8') > self::MAX_LEN) {
            return false;
        }
        // 纯标点/符号/空白无检索价值
        if (preg_match('/^[\s\p{P}\p{S}]+$/u', $tag) === 1) {
            return false;
        }
        // 首尾冒号 = 残缺的适用性标注（漏填了维度名或取值）
        $first = $tag[0];
        $last  = $tag[mb_strlen($tag, 'UTF-8') - 1];
        if ($first === ':' || $last === ':') {
            return false;
        }
        return true;
    }

    /**
     * 按维度分组适用性标签，供前台渲染。
     *
     * @param  array<int,array{dim:string,value:string,raw:string}> $scopes
     * @return array<string,array<int,string>> 维度中文名 => 取值数组
     */
    public static function groupByDimension(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $scope) {
            $label = self::SCOPE_DIMENSIONS[$scope['dim']] ?? $scope['dim'];
            $out[$label][] = $scope['value'];
        }
        return $out;
    }

    /**
     * 判断某条内容是否还需要提示补充适用范围。
     *
     * 【为什么不做自动推断】
     * 试过「从工序名推断」，但工序是系统已有的一等维度（process_id），
     * 前台导航与详情页都已展示，写进标签是冗余；且 22/74 条绑的是「其它」，
     * 推出「适用范围：工序 其它」毫无信息量，反而让人误以为适用范围已认真标注过。
     *
     * 线体 / 机台 / 版本同理无法从标题可靠推断（「护套押出8线」的 8 可能是
     * 线号也可能是机台号）。错误信息比缺失信息危害大得多 —— 用户看到标签
     * 就会停止自行判断，所以宁可留空让人工填。
     *
     * @return array<int,string> 推荐维度名列表，供后台提示
     */
    public static function suggestedDimensions(): array
    {
        return self::SUGGESTED_DIMENSIONS;
    }
}
