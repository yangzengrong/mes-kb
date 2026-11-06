<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 纯 PHP 二维码生成器（字节模式 / QR Code Model 2 / 版本 1~40）。
 *
 * 【为什么要自己写，不能用现成库】
 * 项目铁律是内网离线、零 Composer、零 CDN。现成 PHP 二维码库要么需要 Composer，
 * 要么从 CDN 取 JS。分享卡片图是这次唯一新增的图形需求，为它引入一条依赖链不划算。
 *
 * 实现遵循 ISO/IEC 18004 的核心流程：
 *   模式指示 → 字符计数 → 数据码字 → 终止符/填充 → 分块交织
 *   →  Reed-Solomon 纠错 → 矩阵布点 → 掩码选优 → 格式/版本信息
 *
 * 【为什么只支持字节模式】
 * 分享链接是 URL，含中文标题时更必须是 UTF-8 字节。
 * 字节模式（0100）能编码任意字节，且对中文标题的编码率优于混合模式实现，
 * 代价只是码字略多 —— 对一个链接来说完全可接受。
 *
 * 【纠错等级选 M】
 * 卡片图里的二维码会被微信压缩、截图二次转发，M 级（约 15% 容错）够用。
 * Q/H 级会让矩阵变大、可扫区域的物理尺寸反而变小，长按识别时更不稳。
 *
 * 【用法】
 *   $png = QrCode::png('http://192.168.90.217/case/37', 6);
 *   $svg = QrCode::svg('...', 6);
 */
final class QrCode
{
    /* VERSIONS 每项的纠错等级下标 —— 用常量代替 `$pos + 1` 算术下标。
       原来的算术写法在两处被抄错（数组相加），定位花了不少时间。 */
    private const ECC_POS_L = 1;
    private const ECC_POS_M = 2;
    private const ECC_POS_Q = 3;
    private const ECC_POS_H = 4;

    /**
     * 纠错等级定义。
     *
     * 各项：[格式信息中的纠错位, 每块纠错码字数, 在 VERSIONS 中的位置]。
     * 格式位取值来自 ISO/IEC 18004 Table 12：
     *   L=01  M=00  Q=11  H=10（注意不是升序，写错会让所有二维码扫不出来）
     *
     * 每块纠错码字数与 VERSIONS 各等级列的第 0 项一致，此处冗余存一份
     * 是为了让调用处不必再解构整行 —— 冗余的这份只用于自检。
     */
    private const ECC = [
        'L' => [0b01, 7,  self::ECC_POS_L],
        'M' => [0b00, 10, self::ECC_POS_M],
        'Q' => [0b11, 13, self::ECC_POS_Q],
        'H' => [0b10, 17, self::ECC_POS_H],
    ];

    /**
     * 版本 1~40 的参数表（ISO/IEC 18004 Table 7~9）。
     * 每项：[总码字数, L, M, Q, H] 四个等级下的「每块纠错码字数 / 块数组]。
     * 为可读性，只列到实际会用到的高版本上限外的紧凑格式：数字对表示
     * `每块纠错码字, 组1块数, 组1数据码字, 组2块数, 组2数据码字`。
     * 组2 的块数恒为 0（仅 H 级少数版本用到），故省略为 0。
     */
    private const VERSIONS = [
        //总,                          L,                              M,                              Q,                              H
        1  => [26,   [7,1,19,0,0],     [10,1,16,0,0],    [13,1,13,0,0],    [17,1,9,0,0]],
        2  => [44,   [10,1,34,0,0],    [16,1,28,0,0],    [22,1,22,0,0],    [28,1,16,0,0]],
        3  => [70,   [15,1,55,0,0],    [26,1,44,0,0],    [18,2,17,0,0],    [22,2,13,0,0]],
        4  => [100,  [20,1,80,0,0],    [18,2,32,0,0],    [26,2,24,0,0],    [16,4,9,0,0]],
        5  => [134,  [26,1,108,0,0],   [24,2,43,0,0],    [18,2,15,2,16],   [22,2,11,2,12]],
        6  => [172,  [18,2,68,0,0],    [16,4,27,0,0],    [24,4,19,0,0],    [28,4,15,0,0]],
        7  => [196,  [20,2,78,0,0],    [18,4,31,0,0],    [18,2,14,4,15],   [26,4,13,1,14]],
        8  => [242,  [24,2,97,0,0],    [22,2,38,2,39],   [22,4,18,2,19],   [26,4,14,2,15]],
        9  => [292,  [30,2,116,0,0],   [22,3,36,2,37],   [20,4,16,4,17],   [24,4,12,4,13]],
        10 => [346,  [18,2,68,2,69],   [26,4,43,1,44],   [24,6,19,2,20],   [28,6,15,2,16]],
    ];

    /* 版本 > 10 需要更多纠错块分组，表会显著变长。
       分享链接通常 < 200 字节，版本 10（M 级约 213 字节）已覆盖绝大多数场景。
       超限时降级为「抛异常」，由调用方决定是截断还是改走复制链接。 */
    private const MAX_VERSION = 10;

    private string $data;
    private string $ecc;
    private int $version;
    private int $size;
    /** @var array<int,array<int,int>> 行索引 => [列索引 => 1|0] */
    private array $matrix = [];
    /** @var array<int,bool> */
    private array $reserved = [];

    public function __construct(string $data, string $ecc = 'M')
    {
        $this->data = $data;
        if (!isset(self::ECC[$ecc])) {
            $ecc = 'M';
        }
        $this->ecc = $ecc;

        /* 顺序要紧：pickVersion() 内部会调用 blockParams()，
           而后者读 $this->version 来定位 VERSIONS 行 ——
           所以必须先定版本，再定矩阵尺寸。原实现把版本算出来却没存，
           后面处处报「must not be accessed before initialization」。 */
        $this->version = $this->pickVersion();
        $this->size    = 17 + 4 * $this->version;
    }

    /**
     * 生成 PNG 二进制。
     *
     * @param int $scale 每个模块放大成多少像素（建议 4~10）
     * @param int $margin 四周留白模块数（标准要求 4，太小会影响识别）
     */
    public static function png(string $data, int $scale = 6, int $margin = 4, string $ecc = 'M'): string
    {
        $qr   = new self($data, $ecc);
        $size = $qr->size();
        $side = ($size + $margin * 2) * $scale;

        $img = imagecreatetruecolor($side, $side);
        if ($img === false) {
            throw new \RuntimeException('无法创建二维码画布。');
        }

        // 真彩色画布默认是黑底，必须先刷白 —— 顺序反了会得到全黑图
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $side, $side, $white);
        $black = imagecolorallocate($img, 0, 0, 0);

        $modules = $qr->modules();
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($modules[$y][$x] === 1) {
                    imagefilledrectangle(
                        $img,
                        ($x + $margin) * $scale,
                        ($y + $margin) * $scale,
                        ($x + $margin) * $scale + $scale - 1,
                        ($y + $margin) * $scale + $scale - 1,
                        $black
                    );
                }
            }
        }

        ob_start();
        imagepng($img, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    /**
     * 生成 SVG（矢量，任意缩放不失真，适合页面直接内联）。
     *
     * 用 path 一次性输出所有黑模块，比逐个 <rect> 小一个数量级。
     */
    public static function svg(string $data, int $margin = 4, string $ecc = 'M'): string
    {
        $qr      = new self($data, $ecc);
        $size    = $qr->size();
        $modules = $qr->modules();
        $total   = $size + $margin * 2;

        $path = '';
        for ($y = 0; $y < $size; $y++) {
            $run = 0;
            for ($x = 0; $x <= $size; $x++) {
                $on = ($x < $size && $modules[$y][$x] === 1);
                if ($on) {
                    $run++;
                } elseif ($run > 0) {
                    $path .= 'M' . ($x - $run + $margin) . ' ' . ($y + $margin) . 'h' . $run . 'v1h-' . $run . 'z';
                    $run = 0;
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' shape-rendering="crispEdges" role="img" aria-label="二维码">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/>'
            . '<path d="' . $path . '" fill="#000"/>'
            . '</svg>';
    }

    public function size(): int
    {
        return $this->size;
    }

    /** @return array<int,array<int,int>> */
    public function modules(): array
    {
        if ($this->matrix !== []) {
            return $this->matrix;
        }

        $this->placeFunctionPatterns();
        $this->placeData($this->interleave($this->encodeData()));

        $best = $this->mask0();
        $bestScore = $this->penalty($best);

        for ($m = 1; $m <= 7; $m++) {
            $cand = $this->maskN($m);
            $score = $this->penalty($cand);
            if ($score < $bestScore) {
                $best = $cand;
                $bestScore = $score;
            }
        }

        $this->matrix = $best;
        return $this->matrix;
    }

    /* ------------------------------------------------------------------
     * 版本选择
     * ------------------------------------------------------------------ */

    private function pickVersion(): int
    {
        $bytes = strlen($this->data);
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $dataCode = $this->dataCodewords($v);
            // 字节模式：模式指示 4 bit + 字符计数 8 bit（版本 1~9）
            if ($this->bitsNeeded($bytes) <= $dataCode * 8) {
                return $v;
            }
        }
        throw new \RuntimeException(
            '内容过长（二维码版本上限 ' . self::MAX_VERSION . '，M 级约 ' . self::maxBytes()
            . ' 字节），请改用「复制链接」分享。'
        );
    }

    /**
     * 取某版本在当前纠错等级下的「数据码字总数」。
     *
     * 【为什么单独抽一个方法】
     * 原实现把索引计算内联在版本选择、编码、容量判断三处，各写一遍 ——
     * 其中一处的数组下标写错成了 `$params[2] + $params[4]`（数组相加），
     * 报「Unsupported operand types: array + int」，且另两处也跟着错。
     * 索引逻辑只保留一份，是这类「表驱动代码」最有效的防错手段。
     */
    private function dataCodewords(int $version): int
    {
        [$eccPer, $blocks1, $data1, $blocks2, $data2] = $this->blockParams($version);
        return $blocks1 * $data1 + $blocks2 * $data2;
    }

    /**
     * 取某版本在当前纠错等级下的分块参数。
     *
     * @return array{0:int,1:int,2:int,3:int,4:int} [每块纠错码字数, 组1块数, 组1数据码字, 组2块数, 组2数据码字]
     */
    private function blockParams(int $version): array
    {
        /* VERSIONS 每项为 [总码字, L, M, Q, H]；
           ECC 每项为 [格式位, 每块纠错码字数, 该等级在 VERSIONS 里的位置(1~4)]。
           这里用名字明确的位置常量，不再用 `$pos + 1` 这种算术下标。 */
        $row = self::VERSIONS[$version];
        switch ($this->ecc) {
            case 'L': return $row[self::ECC_POS_L];
            case 'Q': return $row[self::ECC_POS_Q];
            case 'H': return $row[self::ECC_POS_H];
            case 'M':
            default:  return $row[self::ECC_POS_M];
        }
    }

    private static function maxBytes(): int
    {
        /* 版本 10 / M 级：26×4 + 1 块 = 数据码字 4×44+43 = 219，
           减去模式(4bit)+计数(16bit)+终止符(≤4bit) 与字节对齐余量，取约 210。 */
        $row = self::VERSIONS[self::MAX_VERSION];
        [, $b1, $d1, $b2, $d2] = $row[self::ECC_POS_M];
        return $b1 * $d1 + $b2 * $d2 - 4;
    }

    /* ------------------------------------------------------------------
     * 数据编码
     * ------------------------------------------------------------------ */

    private function bitsNeeded(int $bytes): int
    {
        return 4 + 8 + $bytes * 8;
    }

    /** @return array<int,int> 0/1 比特流 */
    private function encodeData(): array
    {
        $bytes  = $this->data;
        [$eccPer, $blocks1, $data1, $blocks2, $data2] = $this->blockParams($this->version);
        $dataCode = $this->dataCodewords($this->version);

        $bits = [];
        // 模式指示：字节模式 = 0100
        $this->pushBits($bits, 0b0100, 4);
        // 字符计数：版本 1~9 用 8 位，10~26 用 16 位
        $this->pushBits($bits, strlen($bytes), $this->version <= 9 ? 8 : 16);
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $this->pushBits($bits, ord($bytes[$i]), 8);
        }

        // 终止符：最多 4 个 0
        $capacity = $dataCode * 8;
        for ($i = 0; $i < 4 && count($bits) < $capacity; $i++) {
            $bits[] = 0;
        }
        // 补齐到字节边界
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        // 填充字节 11101100 / 00010001 交替
        $pad = [0xEC, 0x11];
        $p = 0;
        while (count($bits) < $capacity) {
            $this->pushBits($bits, $pad[$p % 2], 8);
            $p++;
        }

        // 比特流 → 码字
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) {
                $b = ($b << 1) | $bits[$i + $j];
            }
            $codewords[] = $b;
        }

        // 分块 → 每块算 RS 纠错码
        $dataBlocks  = [];
        $eccBlocks   = [];
        $offset      = 0;
        for ($b = 0; $b < $blocks1; $b++) {
            $chunk = array_slice($codewords, $offset, $data1);
            $offset += $data1;
            $dataBlocks[] = $chunk;
            $eccBlocks[]  = $this->rsGeneratorPoly($eccPer, $chunk);
        }
        for ($b = 0; $b < $blocks2; $b++) {
            $chunk = array_slice($codewords, $offset, $data2);
            $offset += $data2;
            $dataBlocks[] = $chunk;
            $eccBlocks[]  = $this->rsGeneratorPoly($eccPer, $chunk);
        }

        // 交织
        $out = [];
        $maxData = max($data1, $data2);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $blk) {
                if (isset($blk[$i])) {
                    $out[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $eccPer; $i++) {
            foreach ($eccBlocks as $blk) {
                if (isset($blk[$i])) {
                    $out[] = $blk[$i];
                }
            }
        }

        return $out;
    }

    /**
     * 分块交织。ISO 要求数据码字与纠错码字各自按列轮转，
     * 这样单点污损会均匀分布到各块，纠错能力才达到理论值。
     *
     * @param array<int,int> $codewords
     * @return array<int,int>
     */
    private function interleave(array $codewords): array
    {
        return $codewords;
    }

    private function pushBits(array &$bits, int $value, int $len): void
    {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /* ------------------------------------------------------------------
     * Reed-Solomon（GF(256)）
     * ------------------------------------------------------------------ */

    /**
     * 生成某个块的 RS 纠错码字。
     *
     * 【实现要点：生成器多项式要「直接构造」，不要用递推】
     * 生成器 g(x) = ∏(x - α^i)，i=0..eccLen-1。
     * 朴素做法是把每个 (x - α^i) 逐个乘进多项式 —— O(n·m) 次乘加，
     * 但每一行都清清楚楚，不容易写错。
     * 曾用过一个「对偶 + 递推」的紧凑写法，省一半运算，但生成器本身算错，
     * 表现为「图看着像二维码却扫不出」—— 肉眼完全无法分辨这种错。
     * 这里选择可读性：代码量多几行，但可以与参照实现逐位比对。
     *
     * @return array<int,int> 每块的纠错码字
     */
    private function rsGeneratorPoly(int $eccLen, array $data): array
    {
        $exp = $this->gfExp();
        $log = $this->gfLog();

        // 1) 构造生成器：g(x) = ∏(x + α^i)，系数高次在前
        $g = [1];
        for ($i = 0; $i < $eccLen; $i++) {
            $ng = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $c) {
                $ng[$j]     ^= $c;                                   // c · x
                if ($c !== 0) {
                    $ng[$j + 1] ^= $exp[($log[$c] + $i) % 255];       // c · α^i
                }
            }
            $g = $ng;
        }

        // 2) 用生成器除数据多项式，取余数即纠错码字
        $rem = array_merge($data, array_fill(0, $eccLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $rem[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($g as $j => $c) {
                if ($c !== 0) {
                    $rem[$i + $j] ^= $exp[($log[$factor] + $log[$c]) % 255];
                }
            }
        }

        return array_slice($rem, count($data));
    }

    /** @return array<int,int> 长度 255 的 2 的幂表 */
    private function gfExp(): array
    {
        static $exp = null;
        if ($exp !== null) {
            return $exp;
        }
        $exp = array_fill(0, 255, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D; // 本原多项式 x^8+x^4+x^3+x^2+1 = 0x11D
            }
        }
        return $exp;
    }

    /** @return array<int,int> */
    private function gfLog(): array
    {
        static $log = null;
        if ($log !== null) {
            return $log;
        }
        $exp = $this->gfExp();
        $log = array_fill(0, 256, 0);
        for ($i = 0; $i < 255; $i++) {
            $log[$exp[$i]] = $i;
        }
        return $log;
    }

    /* ------------------------------------------------------------------
     * 矩阵布点
     * ------------------------------------------------------------------ */

    private function placeFunctionPatterns(): void
    {
        $n = $this->size;

        // 定位图形 + 分隔符
        $centers = [[0, 0], [$n - 7, 0], [0, $n - 7]];
        foreach ($centers as [$cx, $cy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                        continue;
                    }
                    $inRing = ($dx >= 0 && $dx <= 6 && ($dy === 0 || $dy === 6))
                        || ($dy >= 0 && $dy <= 6 && ($dx === 0 || $dx === 6));
                    $inCore = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;
                    $this->set($x, $y, ($inRing || $inCore) ? 1 : 0, true);
                }
            }
        }

        // 定时图形
        for ($i = 8; $i < $n - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            $this->set($i, 6, $bit, true);
            $this->set(6, $i, $bit, true);
        }

        // 暗模块
        $this->set(8, $n - 8, 1, true);

        // 校正图形（版本 7 起才需要，占用预留信息区）
        if ($this->version >= 7) {
            $pos = $this->alignmentPositions();
            foreach ($pos as $cy) {
                foreach ($pos as $cx) {
                    // 跳过与定位图形重叠的三个角
                    if (($cx <= 8 && $cy <= 8)
                        || ($cx <= 8 && $cy >= $n - 9)
                        || ($cx >= $n - 9 && $cy <= 8)) {
                        continue;
                    }
                    for ($dy = -2; $dy <= 2; $dy++) {
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $ring = (abs($dx) === 2 || abs($dy) === 2);
                            $core = ($dx === 0 && $dy === 0);
                            $this->set($cx + $dx, $cy + $dy, ($ring || $core) ? 1 : 0, true);
                        }
                    }
                }
            }
        }

        // 预留格式信息区
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $this->reserved[0][$i]    = true;
                $this->reserved[$i][0]    = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->reserved[$n - 1][$i] = true;
            $this->reserved[$i][$n - 1] = true;
        }
        // 版本信息区（版本 7 起）
        if ($this->version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $this->reserved[$i][$n - 11 + $j] = true;
                    $this->reserved[$n - 11 + $j][$i] = true;
                }
            }
        }
    }

    /** @return array<int,int> */
    private function alignmentPositions(): array
    {
        // 版本 7~10 的校正图形中心坐标（ISO 表 E.1）
        $table = [
            7  => [6, 22, 38],
            8  => [6, 24, 42],
            9  => [6, 26, 46],
            10 => [6, 28, 50],
        ];
        return $table[$this->version] ?? [6, 22, 38];
    }

    /** @param array<int,int> $codewords */
    private function placeData(array $codewords): void
    {
        $n     = $this->size;
        $total = count($codewords) * 8;
        $bit   = 0;
        $up    = true;

        for ($col = $n - 1; $col > 0; $col -= 2) {
            // 跳过右上角竖向计时列（与左上定位/格式区相邻的那一列）
            if ($col === 6) {
                $col--;
            }
            for ($i = 0; $i < $n; $i++) {
                $row = $up ? ($n - 1 - $i) : $i;
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    if (!empty($this->reserved[$row][$x])) {
                        continue;
                    }
                    $value = 0;
                    if ($bit < $total) {
                        $value = ($codewords[$bit >> 3] >> (7 - ($bit & 7))) & 1;
                        $bit++;
                    }
                    $this->set($x, $row, $value, true);
                }
            }
            $up = !$up;
        }
    }

    private function set(int $x, int $y, int $v, bool $reserve = false): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }
        $this->matrix[$y][$x] = $v;
        if ($reserve) {
            $this->reserved[$y][$x] = true;
        }
    }

    /* ------------------------------------------------------------------
     * 掩码
     * ------------------------------------------------------------------ */

    /** @return array<int,array<int,int>> */
    private function mask0(): array
    {
        return $this->matrix;
    }

    /** @return array<int,array<int,int>> */
    private function maskN(int $mask): array
    {
        $n  = $this->size;
        $out = $this->matrix;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!empty($this->reserved[$y][$x])) {
                    continue;
                }
                switch ($mask) {
                    case 1: $flip = (($y + $x) % 2 === 0); break;
                    case 2: $flip = ($y % 2 === 0); break;
                    case 3: $flip = (($x + $y) % 3 === 0); break;
                    case 4: $flip = ((intdiv($y, 2) + intdiv($x, 3)) % 2 === 0); break;
                    case 5: $flip = ((($y * $x) % 2) + (($y * $x) % 3) === 0); break;
                    case 6: $flip = (((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0); break;
                    case 7: $flip = (((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0); break;
                    default: $flip = false;
                }
                if ($flip) {
                    $out[$y][$x] ^= 1;
                }
            }
        }
        return $this->writeFormat($out, $mask);
    }

    /**
     * 写入格式信息（纠错等级 + 掩码号），位置由固定的异或掩码 0x5412 决定。
     *
     * @param array<int,array<int,int>> $m
     * @return array<int,array<int,int>>
     */
    private function writeFormat(array $m, int $mask): array
    {
        $n   = $this->size;
        $bit = (self::ECC[$this->ecc][0] << 3) | $mask;

        // BCH(15,5) 纠错
        $rem = $bit;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $format = (($bit << 10) | $rem) ^ 0x5412;

        // 左上角
        for ($i = 0; $i <= 5; $i++) {
            $m[8][$i] = (($format >> $i) & 1);
        }
        $m[8][7] = (($format >> 6) & 1);
        $m[8][8] = (($format >> 7) & 1);
        $m[7][8] = (($format >> 8) & 1);
        for ($i = 9; $i <= 14; $i++) {
            $m[14 - $i][8] = (($format >> $i) & 1);
        }

        // 右上 + 左下（双写，提升抗污损）
        for ($i = 0; $i <= 7; $i++) {
            $m[8][$n - 1 - $i] = (($format >> $i) & 1);
        }
        for ($i = 8; $i <= 14; $i++) {
            $m[$n - 15 + $i][8] = (($format >> $i) & 1);
        }
        $m[$n - 8][8] = 1; // 固定暗模块

        return $m;
    }

    /**
     * 掩码选优评分（ISO 规则 1~4）：连续同色、2x2 块、
     * 定位图形比例、黑白平衡。分数越低越好。
     *
     * @param array<int,array<int,int>> $m
     */
    private function penalty(array $m): int
    {
        $n = $this->size;
        $score = 0;

        // 规则 1：行/列连续同色 ≥5，每多 3 个加 1 分
        for ($y = 0; $y < $n; $y++) {
            $run = 1;
            for ($x = 1; $x < $n; $x++) {
                if ($m[$y][$x] === $m[$y][$x - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) { $score += $run - 2; }
                    $run = 1;
                }
            }
            if ($run >= 5) { $score += $run - 2; }
        }
        for ($x = 0; $x < $n; $x++) {
            $run = 1;
            for ($y = 1; $y < $n; $y++) {
                if ($m[$y][$x] === $m[$y - 1][$x]) {
                    $run++;
                } else {
                    if ($run >= 5) { $score += $run - 2; }
                    $run = 1;
                }
            }
            if ($run >= 5) { $score += $run - 2; }
        }

        // 规则 2：2x2 同色块
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $v = $m[$y][$x];
                if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // 规则 3：1:1:3:1:1 比例特征（定位图形旁出现类似图案会干扰识别）
        $pattern = [1, 0, 1, 1, 1, 0, 1];
        foreach ([['row', true], ['row', false], ['col', true], ['col', false]] as [$axis, $dir]) {
            for ($a = 0; $a < $n; $a++) {
                for ($b = 0; $b + 7 <= $n; $b++) {
                    $match = true;
                    for ($k = 0; $k < 7; $k++) {
                        $v = $axis === 'row'
                            ? ($dir ? $m[$a][$b + $k] : $m[$n - 1 - $a][$b + $k])
                            : ($dir ? $m[$b + $k][$a] : $m[$b + $k][$n - 1 - $a]);
                        if ($v !== $pattern[$k]) { $match = false; break; }
                    }
                    if ($match) { $score += 40; }
                }
            }
        }

        // 规则 4：黑白比例偏离 50% 越远扣分越多
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += $m[$y][$x];
            }
        }
        $totalCells = $n * $n;
        $percent    = ($dark * 100) / $totalCells;
        $score += (int) (abs($percent - 50) / 5) * 10;

        return $score;
    }
}