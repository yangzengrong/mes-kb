<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 富文本 HTML 白名单净化器。
 *
 * 富文本编辑器产出的内容不能直接信任：粘贴自 Word / 网页的内容可能携带
 * script、事件属性（onclick 等）、javascript: 伪协议等攻击载荷。
 *
 * 策略：只保留白名单标签与白名单属性，其余一律剥离；
 * src / href 仅允许 http(s) 与站内相对路径，明确拒绝 data: / javascript:。
 * 纯正则实现，零外部依赖（适配内网部署，不依赖 php-xml 扩展）。
 */
final class HtmlSanitizer
{
    /** 允许保留的标签 => 允许保留的属性 */
    private const ALLOWED = [
        'p'      => ['style'],
        'br'     => [],
        'div'    => ['style'],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'u'      => [],
        's'      => [],
        'strike' => [],
        'del'    => [],
        'sub'    => [],
        'sup'    => [],
        'ul'     => ['style'],
        'ol'     => ['style'],
        'li'     => ['style'],
        'table'  => ['style', 'width', 'border', 'cellspacing', 'cellpadding', 'align'],
        'thead'  => ['style'],
        'tbody'  => ['style'],
        'tr'     => ['style', 'height', 'bgcolor'],
        'td'     => ['colspan', 'rowspan', 'style', 'width', 'height', 'align', 'valign', 'bgcolor'],
        'th'     => ['colspan', 'rowspan', 'style', 'width', 'height', 'align', 'valign', 'bgcolor'],
        'h2'     => ['style'],
        'h3'     => ['style'],
        'h4'     => ['style'],
        'span'   => ['style'],
        'font'   => ['size', 'color'],
        'img'    => ['src', 'alt', 'title', 'style', 'width', 'height'],
        'a'      => ['href', 'title', 'target'],
    ];

    /** 整块删除（连同内容）的标签 */
    private const DROP_WITH_CONTENT = 'script|style|iframe|object|embed|form|svg|math|noscript|template|link|meta|base|title';

    /** 仅剥壳保留内容的标签 */
    private const DROP_KEEP_CONTENT = 'input|button|select|textarea|video|audio|source|canvas|applet|frame|frameset|html|head|body|!--';

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // 1. 连内容一起删除的危险块
        $html = (string) preg_replace('#<\s*(' . self::DROP_WITH_CONTENT . ')\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        // 2. 自闭合 / 未闭合的危险标签
        $html = (string) preg_replace('#<\s*/?\s*(' . self::DROP_WITH_CONTENT . '|' . self::DROP_KEEP_CONTENT . ')\b[^>]*>?#i', '', $html);
        // 3. 注释与文档声明（可能藏 IE 条件注释攻击）
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $html = (string) preg_replace('#<![^>]*>#s', '', $html);

        // 4. 逐标签过滤
        $html = (string) preg_replace_callback(
            '#<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9-]*)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>?#s',
            static function (array $m): string {
                return self::filterTag($m);
            },
            $html
        );

        return trim($html);
    }

    /**
     * 是否疑似富文本 HTML（用于前台展示时区分旧纯文本内容）。
     */
    public static function looksLikeHtml(string $content): bool
    {
        return (bool) preg_match('/<\s*\/?\s*(p|br|div|strong|b|em|i|u|s|ol|ul|li|table|t[dhr]|thead|tbody|h[1-6]|span|font|img|a)\b/i', $content);
    }

    /**
     * @param array<int,string> $m
     */
    private static function filterTag(array $m): string
    {
        $closing = $m[1] === '/';
        $name    = strtolower($m[2]);
        $attrStr = $m[3] ?? '';

        if (!isset(self::ALLOWED[$name])) {
            return ''; // 非白名单标签直接剥离（保留其文本内容）
        }
        if ($name === 'br') {
            return '<br>';
        }
        if ($closing) {
            return '</' . $name . '>';
        }

        $kept = [];
        if (preg_match_all('/([a-zA-Z-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $attrStr, $attrs, PREG_SET_ORDER)) {
            foreach ($attrs as $attr) {
                $attrName  = strtolower($attr[1]);
                $attrValue = $attr[2] ?? $attr[3] ?? $attr[4] ?? '';
                if (!in_array($attrName, self::ALLOWED[$name], true)) {
                    continue;
                }
                $safeValue = self::filterAttr($name, $attrName, $attrValue);
                if ($safeValue === null) {
                    continue;
                }
                $kept[] = $attrName . '="' . e($safeValue) . '"';
            }
        }

        // img 缺少合法 src、a 缺少合法 href 时整个标签丢弃，避免残留无意义/可滥用的空标签
        if ($name === 'img' || $name === 'a') {
            $required = $name === 'img' ? 'src="' : 'href="';
            $hasRequired = false;
            foreach ($kept as $attribute) {
                if (strpos($attribute, $required) === 0) {
                    $hasRequired = true;
                    break;
                }
            }
            if (!$hasRequired) {
                return '';
            }
        }

        return '<' . $name . ($kept !== [] ? ' ' . implode(' ', $kept) : '') . '>';
    }

    private static function filterAttr(string $tag, string $attr, string $value): ?string
    {
        if ($attr === 'style') {
            return self::filterStyle($value);
        }

        if ($attr === 'src' || $attr === 'href') {
            $value = trim(str_replace(['"', "'", '\\', '`'], '', $value));
            if ($value === '' || strlen($value) > 2000) {
                return null;
            }
            // 拒绝危险伪协议；data: 一律不允许（防止 data:text/html 变体）
            if (preg_match('/^(javascript|vbscript|data|file)\s*:/i', $value)) {
                return null;
            }
            // 仅允许 http(s)、协议相对、站内相对路径
            if (!preg_match('#^(?:https?:|/|\.)#i', $value) && !preg_match('#^[a-zA-Z0-9._-]+(?:\?|$|/)#', $value)) {
                return null;
            }
            return $value;
        }

        if ($attr === 'size') {
            return preg_match('/^[1-7]$/', $value) === 1 ? $value : null;
        }

        if ($attr === 'color' || $attr === 'bgcolor') {
            return preg_match('/^[#a-zA-Z0-9 ,().%]+$/', $value) === 1 ? $value : null;
        }

        // 数值型展示属性（UEditor 表格 / 图片常带）
        if (in_array($attr, ['width', 'height', 'border', 'cellspacing', 'cellpadding', 'colspan', 'rowspan'], true)) {
            return preg_match('/^\d{1,4}%?$/', $value) === 1 ? $value : null;
        }

        if ($attr === 'align') {
            return in_array(strtolower($value), ['left', 'center', 'right', 'justify'], true) ? strtolower($value) : null;
        }

        if ($attr === 'valign') {
            return in_array(strtolower($value), ['top', 'middle', 'bottom'], true) ? strtolower($value) : null;
        }

        if ($attr === 'target') {
            return $value === '_blank' ? '_blank' : null;
        }

        // alt / title：纯文本
        $value = strip_tags($value);
        return $value === '' ? null : mb_substr($value, 0, 200, 'UTF-8');
    }

    private static function filterStyle(string $style): ?string
    {
        $kept = [];
        if (preg_match_all('/([a-zA-Z-]+)\s*:\s*([^;]+)/', $style, $declarations, PREG_SET_ORDER)) {
            foreach ($declarations as $declaration) {
                $property = strtolower(trim($declaration[1]));
                $value    = trim($declaration[2]);
                // 只放行排版 / 颜色类属性，且值中不允许出现 url / expression 等
                if (!in_array($property, [
                    'font-size', 'font-family', 'font-weight', 'color', 'background-color',
                    'text-align', 'text-decoration', 'line-height',
                ], true)) {
                    continue;
                }
                if (preg_match('/url|expression|javascript|behavior|@|position|display/i', $value)) {
                    continue;
                }
                // 去掉字体名等值中的引号，避免属性截断
                $value = str_replace(['"', "'", '\\', '`'], '', $value);
                if (mb_strlen($value, 'UTF-8') > 60) {
                    continue;
                }
                $kept[] = $property . ': ' . $value;
            }
        }
        return $kept === [] ? null : implode('; ', $kept);
    }
}
