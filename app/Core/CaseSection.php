<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 正文小节切分器（断点C：排查记录折叠区）。
 *
 * 【要解决什么】
 * 案例正文（phenomenon 单编辑器）目前只记「现象 / 原因 / 方案」这类**结论**，
 * 但新人遇到相似故障时最想要的是「当时怎么一步步查出来的」——
 * 那是老手最值钱的隐性知识，现在完全没地方存，也没地方看。
 *
 * 【为什么用「小标题约定」而不是新增数据库列】
 * 1) 零表结构变更是本项目硬约束；
 * 2) 库里已有 4 条案例自发用了 `<h4>` 小标题（「故障现象」「解决方案」），
 *    说明这个书写习惯已经形成，约定小标题顺水推舟，不需要管理员学新东西；
 * 3) 新增字段则要改 3 处读写 + 迁移历史数据，且旧数据仍是空的。
 *
 * 【约定规则】
 * 正文里出现「排查过程」类小标题时，该小标题之后的内容会被切出来，
 * 放进前台默认收起的折叠区。没有这个小标题的案例，行为与现在完全一致。
 *
 * 识别方式：`<h3>`~`<h5>` 或「整段加粗的短行」都算小标题（线上有 27 条用了 strong/b），
 * 但要求**小标题文字长度 ≤ 20 字** —— 超过就不可能是标题而是正文，避免误切。
 *
 * 【为什么不猜「排查」二字以外的说法】
 * 「定位」「排查」「处理过程」「检查过程」都试过用关键词匹配，
 * 但正文里出现「检查」可能是「检查结果为正常」这种句子内容而非小节标题。
 * 宁可少识别，也不能把正文的一段话误当小节抽走 —— 那是破坏内容。
 * 所以只认「独立小节 + 短标题」这个结构性特征。
 */
final class CaseSection
{
    /**
     * 排查类小标题的关键词。
     *
     * 必须是「怎么查出来的」语义，不含「解决方案」——
     * 方案是给用户的结论，默认展开；排查过程是给后来者的路径，默认收起。
     */
    private const TRACE_KEYWORDS = ['排查', '定位过程', '排查过程', '处理过程', '检查过程', '诊断过程', '查找过程'];

    /** 小标题最大长度（超过则视为正文，避免误切）。 */
    private const MAX_HEADING_LEN = 20;

    /**
     * 从正文 HTML 中切出「排查过程」小节。
     *
     * @return array{trace:string,main:string,found:bool}
     *   trace  排查过程部分的 HTML（含原小标题，已去掉 h4 包裹）
     *   main   其余内容的 HTML（保持原顺序）
     *   found  是否找到排查小节
     */
    public static function splitTrace(?string $html): array
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return ['trace' => '', 'main' => '', 'found' => false];
        }

        $nodes = self::tokenize($html);
        $traceIndex = null;
        $traceHeading = '';

        foreach ($nodes as $i => $node) {
            if ($node['type'] !== 'heading') {
                continue;
            }
            if (self::isTraceHeading($node['text'])) {
                $traceIndex   = $i;
                $traceHeading = $node['text'];
                break;
            }
        }

        if ($traceIndex === null) {
            // 没有排查小节 —— 原样返回，行为与改造前完全一致
            return ['trace' => '', 'main' => $html, 'found' => false];
        }

        // 排查小节范围：从该小标题之后，取到下一个「同级或更高级别小标题」为止。
        //
        // 边界一次性确定再切片 —— 每个节点只归属一次。
        // （早前一版在 for 循环里累积 main/trace，主内容被重复拼了两遍；
        //   靠仿真的「字符集合比对」断言才发现。）
        $level    = $nodes[$traceIndex]['level'];
        $endIndex = count($nodes);
        for ($i = $traceIndex + 1; $i < count($nodes); $i++) {
            if ($nodes[$i]['type'] === 'heading' && $nodes[$i]['level'] <= $level) {
                $endIndex = $i;
                break;
            }
        }

        $traceRaw = array_slice($nodes, $traceIndex + 1, $endIndex - $traceIndex - 1);
        $mainRaw  = array_merge(
            array_slice($nodes, 0, $traceIndex),
            array_slice($nodes, $endIndex)
        );

        return [
            'trace'  => self::join($traceRaw),
            'main'   => self::join($mainRaw),
            'found'  => true,
            'label'  => $traceHeading,
        ];
    }

    /**
     * 判断一段小标题文字是否表示「排查过程」。
     *
     * 只认结构性特征（确实是小标题）+ 短文本 + 含排查类语义，
     * 三个条件同时满足才切，避免把正文里的一句话误判为小节。
     */
    public static function isTraceHeading(string $text): bool
    {
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
        if ($text === '' || mb_strlen($text, 'UTF-8') > self::MAX_HEADING_LEN) {
            return false;
        }
        foreach (self::TRACE_KEYWORDS as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 把 HTML 切成「顶层节点」序列。
     *
     * 只需识别 h3~h5 与 p/div/ul/ol/table/img 等块级元素，
     * 段落内部的行内标签不切分（否则会破坏富文本结构）。
     *
     * @return array<int,array{type:string,level:int,html:string,text:string}>
     */
    private static function tokenize(string $html): array
    {
        // 先取出所有 h3~h5（作为节点边界），其余按块级标签切
        $pattern = '~(<h[3-5]\b[^>]*>.*?</h[3-5]>|<(?:p|div|ul|ol|table|blockquote|img|hr)\b[^>]*>.*?</(?:p|div|ul|ol|table|blockquote)>|<(?:p|div|ul|ol|table|blockquote|img|hr)\b[^>]*?/?>)~is';

        $nodes = [];
        if (preg_match_all($pattern, $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [['type' => 'other', 'level' => 9, 'html' => $html, 'text' => '']];
        }

        $cursor   = 0;
        $lastIdx  = 0;
        foreach ($m as $set) {
            $raw   = $set[1][0];
            $start = $set[1][1];

            // 上一个节点与本节点之间的纯文本（含未闭合的裸文本），归给上一节点
            if ($start > $cursor && isset($nodes[$lastIdx])) {
                $gap = substr($html, $cursor, $start - $cursor);
                if (trim(strip_tags($gap)) !== '') {
                    $nodes[$lastIdx]['html'] .= $gap;
                }
            }

            $isHeading = (bool) preg_match('~^<h([3-5])\b~i', $raw);
            $node = [
                'type'  => $isHeading ? 'heading' : 'other',
                'level' => $isHeading ? (int) preg_replace('~[^0-9]~', '', $raw[2]) : 9,
                'html'  => $raw,
                'text'  => strip_tags($raw),
            ];
            $nodes[] = $node;
            $lastIdx = count($nodes) - 1;
            $cursor  = $start + strlen($raw);
        }

        if ($nodes === []) {
            return [['type' => 'other', 'level' => 9, 'html' => $html, 'text' => '']];
        }
        return $nodes;
    }

    /** 把节点数组拼回 HTML。 */
    private static function join(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $node['html'];
        }
        return trim($out);
    }

    /**
     * 供后台提示用：排查小节的建议小标题写法。
     *
     * @return array<int,string>
     */
    public static function suggestedHeadings(): array
    {
        return ['排查过程', '定位过程', '处理过程'];
    }
}
