<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 分享卡片图生成器（GD + FreeType，零外部依赖）。
 *
 * 【为什么做成图片，而不是网页按钮直接调微信分享】
 * 微信的硬性限制：
 *   ① 「分享到朋友圈」**不允许网页程序触发** —— 微信明确禁止，只能引导用户
 *      长按图片或点右上角菜单。没有任何 API 能绕过。
 *   ② 微信 JS-SDK 的 wx.config 需要「已备案的公网域名 + 认证公众号」，
 *      当前站点在内网 192.168.90.217，SDK 必然报错。
 * 但「生成一张可长按转发的卡片图」这条路**在��网现在就能用，外网开放后也不用改** ——
 * 长按图片转发是微信的基础能力，不依赖任何 SDK。
 * 所以本类做的事就是：把标题/关键信息/二维码渲染成一张 PNG，交给用户长按。
 *
 * 【为什么不用 SVG 外链】
 * SVG 里如果要嵌二维码得用 base64 data URI，卡片图体积会暴涨；
 * 且微信对 SVG 的兼容性一般。二维码单独出 PNG，页面里用 <img> 拼更可控。
 *
 * 【字体为什么固定找这几个路径】
 * 服务器实测有 Noto Sans CJK（google-noto-cjk）。这里按优先级探测并回退，
 * 找不到中文字体时**降级为「只画二维码 + ASCII 文本」**而不是抛异常 ——
 * 分享功能不可用不该让整个详情页 500。
 */
final class ShareCard
{
    /** 中文字体候选路径（按优先级） */
    private const FONT_CANDIDATES = [
        '/usr/share/fonts/google-noto-cjk/NotoSansCJK-Bold.ttc',
        '/usr/share/fonts/google-noto-cjk/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/google-noto-cjk/NotoSansCJK-DemiLight.ttc',
        '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
        '/usr/share/fonts/truetype/arphic/uming.ttc',
    ];

    private string $title;
    private string $subtitle;
    /** @var array<int,string> */
    private array $meta;
    private string $qrPng;
    private string $footer;

    private int $height = 360;   // 真实高度由 png() 里的 calcHeight() 决定，这里只是占位初值

    private const W = 750;   // 2 倍图，微信里显示更清晰
    private const PAD = 48;

    public function __construct(string $title, string $qrPng)
    {
        $this->title  = $title;
        $this->qrPng  = $qrPng;
        $this->subtitle = '';
        $this->meta   = [];
        $this->footer  = '';
    }

    public function subtitle(string $text): self
    {
        $this->subtitle = $text;
        return $this;
    }

    /** @param array<int,string> $items */
    public function meta(array $items): self
    {
        $this->meta = $items;
        return $this;
    }

    public function footer(string $text): self
    {
        $this->footer = $text;
        return $this;
    }

    /**
     * 渲染为 PNG 二进制。
     *
     * 【布局为什么是「上文字下码」】
     * 微信里长按图片转发时，收件人先看到的是缩略图 —— 标题必须在视觉重心（上方），
     * 二维码放右下角（微信转发不传图，只传链接时用户仍可扫码补全）。
     * 实测微信对含二维码的图转发时会连图带链接一起带，二维码是兜底手段。
     */
    public function png(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('服务器未启用 GD 扩展，无法生成分享卡片。');
        }

        $font = $this->font();
        $this->height = $this->calcHeight($font !== null);

        $img = imagecreatetruecolor(self::W, $this->height);
        if ($img === false) {
            throw new \RuntimeException('无法创建分享卡片画布。');
        }

        // 背景：浅灰蓝，比纯白更适合微信聊天背景
        $bg      = imagecolorallocate($img, 244, 246, 249);
        $cardBg  = imagecolorallocate($img, 255, 255, 255);
        $ink     = imagecolorallocate($img, 28, 28, 26);
        $inkSub  = imagecolorallocate($img, 95, 94, 90);
        $inkHint = imagecolorallocate($img, 138, 136, 128);
        $accent  = imagecolorallocate($img, 24, 95, 165);
        $border  = imagecolorallocate($img, 226, 230, 236);

        imagefilledrectangle($img, 0, 0, self::W, $this->height, $bg);

        $x = self::PAD;
        $y = self::PAD;
        $innerW = self::W - self::PAD * 2;

        // 顶部品牌条
        imagefilledrectangle($img, $x, $y, $x + 6, $y + 34, $accent);
        $y += 52;

        // 主卡片
        $cardH = $this->height - $y - self::PAD;
        imagefilledrectangle($img, $x, $y, $x + $innerW, $y + $cardH, $cardBg);
        // 1px 边框用四个细矩形拼（GD 无描边矩形）
        imagerectangle($img, $x, $y, $x + $innerW, $y + $cardH, $border);

        $cx = $x + 34;
        $cy = $y + 38;

        // 标题（最多 3 行）。行高 48 配 34 号字 —— 行高必须 ≥1.4×字号，
        // 否则中文字形的上下伸展会与相邻行重叠（实测 30/34 就压字了）
        if ($font !== null) {
            $cy = $this->drawWrapped($img, $font, $cx, $cy, $this->title, self::W - self::PAD * 2 - 68, 34, 48, 3, $ink);
        } else {
            $cy = $this->drawFallback($img, $cx, $cy, $this->title, self::W - self::PAD * 2 - 68, 26, 3, $ink);
        }

        // 副标题
        if ($this->subtitle !== '') {
            $cy += 12;
            if ($font !== null) {
                $this->drawText($img, $font, $cx, $cy, $this->subtitle, 19, $inkSub);
            } else {
                $this->drawFallback($img, $cx, $cy, $this->subtitle, self::W - self::PAD * 2 - 68, 16, 1, $inkSub);
            }
            $cy += 34;
        }

        // 标签行
        if ($this->meta !== []) {
            $cx2 = $cx;
            foreach ($this->meta as $chip) {
                /* textWidth 返回 float（要精确到亚像素），而 GD 的矩形参数是 int。
                   这里显式 round + 转 int，否则 PHP 8 的严格类型会直接 TypeError。 */
                $w = (int) round($this->textWidth($font, $chip, 17)) + 26;
                if ($cx2 + $w > $x + $innerW - 34) {
                    $cx2 = $cx;
                    $cy += 34;
                }
                $chipBg = imagecolorallocate($img, 240, 241, 244);
                imagefilledrectangle($img, (int) $cx2, (int) ($cy - 20), (int) ($cx2 + $w), (int) ($cy + 8), $chipBg);
                if ($font !== null) {
                    $this->drawText($img, $font, (int) ($cx2 + 13), (int) $cy, $chip, 17, $inkSub);
                }
                $cx2 += $w + 8;
            }
            $cy += 40;
        }

        // 底部：提示语 + 二维码
        $qrSide = 132;
        $qrX = $x + $innerW - $qrSide - 34;
        $qrY = $y + $cardH - $qrSide - 34;

        if ($this->footer !== '') {
            if ($font !== null) {
                $this->drawText($img, $font, $cx, $qrY + 30, $this->footer, 17, $inkHint);
                $this->drawText($img, $font, $cx, $qrY + 58, '长按图片可转发', 17, $inkHint);
            } else {
                $this->drawFallback($img, $cx, $qrY + 30, 'Scan the QR code to open', $innerW - 200, 14, 2, $inkHint);
            }
        }

        // 二维码（外面套一圈白底 + 细边，保证微信压缩后仍可扫）
        $qrBg = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, $qrX - 10, $qrY - 10, $qrX + $qrSide + 10, $qrY + $qrSide + 10, $qrBg);
        imagerectangle($img, $qrX - 10, $qrY - 10, $qrX + $qrSide + 10, $qrY + $qrSide + 10, $border);
        $qr = @imagecreatefromstring($this->qrPng);
        if ($qr !== false) {
            imagecopyresampled($img, $qr, $qrX, $qrY, 0, 0, $qrSide, $qrSide,
                               imagesx($qr), imagesy($qr));
            imagedestroy($qr);
        }

        // 页脚署名
        if ($font !== null && $this->footer !== '') {
            $this->drawText($img, $font, $cx, $y + $cardH - 22, 'MES · 制造系统知识库', 16, $inkHint);
        }

        ob_start();
        imagepng($img, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    /**
     * 计算卡片高度。
     *
     * 【为什么必须「先算高再画」】
     * 标题行数是动态的，画完才知道高度就无法保证底部二维码与文字不重叠。
     *
     * 【结构：固定开销 + 内容高度】
     *   固定：上下留白 + 品牌条 + 卡片内边距 + 底部二维码区
     *   内容：标题实际行数 × 行高 + 副标题 + 标签行
     * 原实现用「基准 750 + 累加」的方式，行数变化时既算不准又会留大片空白。
     */
    private function calcHeight(bool $hasFont): int
    {
        $innerW = self::W - self::PAD * 2 - 68;
        $titleSize = 34;
        $titleLineH = 48;          // 行高必须明显大于字号，否则上下行字形会压在一起
        $lines = $this->wrapLineCount($this->title, $innerW, $titleSize, 3);

        $contentH = $lines * $titleLineH;
        if ($this->subtitle !== '') {
            $contentH += 46;
        }
        if ($this->meta !== []) {
            $contentH += 48;
        }

        // 固定开销：上留白 + 品牌条 + 卡片上边距 + 内容 + 底部区(二维码 132 + 上下留白) + 下留白
        $fixed = self::PAD          // 上留白
            + 52                     // 品牌条
            + 38                     // 卡片内上边距
            + $contentH
            + 46                     // 内容与底部区之间的呼吸
            + 132 + 34               // 二维码 132 + 下边距
            + self::PAD;             // 下留白

        /* ⚠️ 这里不能拿 self::W（750，那是**宽度**）当下限。
           曾写 max(self::W, $fixed)，内容不足 750 时会把高度强行撑到 750，
           底部多出 100+ px 空白 —— 实测理论 636 却渲染出 750，白白空一截。
           高度下限只保证「二维码 + 标题一行」放得下即可。 */
        return max(360, $fixed);
    }

    private function wrapLineCount(string $text, int $maxW, int $fontSize, int $maxLines): int
    {
        $font = $this->font();
        $perLine = $font !== null
            ? max(1, (int) floor($maxW / ($fontSize * 0.98)))
            : max(1, (int) floor($maxW / ($fontSize * 0.6)));
        $total = (int) ceil(mb_strlen($text, 'UTF-8') / $perLine);
        return min($total, $maxLines);
    }

    /** 逐行绘制并返回下一行的 y */
    private function drawWrapped(
        mixed $img, string $font, int $x, int $y, string $text,
        int $maxW, int $fontSize, int $lineH, int $maxLines, int $color
    ): int {
        $text = trim($text);
        $lines = [];
        $cur = '';
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            $try = $cur . $ch;
            if ($this->textWidth($font, $try, $fontSize) > $maxW && $cur !== '') {
                $lines[] = $cur;
                $cur = $ch;
                if (count($lines) >= $maxLines - 1) {
                    break;
                }
            } else {
                $cur = $try;
            }
        }
        $lines[] = $cur;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }
        $lastIdx = count($lines) - 1;
        // 若因上限截断，末行加省略号
        $consumed = array_sum(array_map(static fn ($s) => mb_strlen($s, 'UTF-8'), $lines));
        if ($consumed < $len && isset($lines[$lastIdx])) {
            $lines[$lastIdx] = mb_substr($lines[$lastIdx], 0, max(1, mb_strlen($lines[$lastIdx], 'UTF-8') - 1), 'UTF-8') . '…';
        }

        foreach ($lines as $li => $line) {
            $this->drawText($img, $font, $x, $y + $li * $lineH, $line, $fontSize, $color);
        }
        return $y + count($lines) * $lineH;
    }

    private function drawText(mixed $img, string $font, int $x, int $y, string $text, int $size, int $color): void
    {
        @imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    /**
     * 无中文字体时的降级绘制（GD 内置点阵字体，只认 ASCII）。
     * 走这个分支说明服务器字体缺失，此时宁可信息不全也不能让页面 500。
     */
    private function drawFallback(mixed $img, int $x, int $y, string $text, int $maxW, int $size, int $maxLines, int $color): int
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        $perLine = max(1, (int) floor($maxW / max(1, $size * 0.55)));
        $lines  = str_split($ascii, $perLine) ?: [''];
        $lines  = array_slice($lines, 0, $maxLines);
        foreach ($lines as $li => $line) {
            @imagestring($img, 5, $x, $y + $li * 22, $line, $color);
        }
        return $y + count($lines) * 22;
    }

    /** 估算文本宽度（用于换行与标签底色宽度） */
    private function textWidth(?string $font, string $text, int $size): float
    {
        if ($font === null) {
            return mb_strlen($text, 'UTF-8') * $size * 0.55;
        }
        // imagettfbbox 的第 2/4 个值是宽度的 min/max，取 max 较准
        $box = @imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return mb_strlen($text, 'UTF-8') * $size;
        }
        $xs = [$box[0], $box[2], $box[4], $box[6]];
        $x1 = max($xs);
        $xs2 = [$box[0], $box[2], $box[4], $box[6]];
        $x0 = min($xs2);
        return (float) ($x1 - $x0);
    }

    private ?string $fontCache = null;
    private bool $fontProbed = false;

    /** 探测可用的中文字体；找不到返回 null（调用方负责降级） */
    private function font(): ?string
    {
        if ($this->fontProbed) {
            return $this->fontCache;
        }
        $this->fontProbed = true;
        if (!function_exists('imagettftext')) {
            return null;
        }
        foreach (self::FONT_CANDIDATES as $path) {
            if (is_file($path) && is_readable($path)) {
                $this->fontCache = $path;
                return $path;
            }
        }
        return null;
    }
}