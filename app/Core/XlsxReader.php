<?php
declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

/**
 * 极简 xlsx 读取器：仅依赖 php-zip + php-xml（Rocky/CentOS 自带），
 * 不引入任何第三方库，符合内网离线部署要求。
 *
 * 支持：共享字符串（t="s"）、内联字符串（t="inlineStr"）、公式结果文本（t="str"）、数值单元格。
 * 不支持：xls（二进制老格式，需用户另存为 xlsx）、合并单元格展开（取左上角值）。
 */
class XlsxReader
{
    /** Excel 关系命名空间 */
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * 读取工作表为二维数组（行 → 列文本，列索引从 0 开始，空单元格补 ''）。
     *
     * @return array{sheet:string,rows:array<int,array<int,string>>}
     */
    public static function read(string $path, string $preferredSheet = ''): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('服务器未启用 php-zip 扩展，无法解析 xlsx 文件。');
        }
        if (!is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('上传的 xlsx 文件不存在或为空。');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('文件无法作为 xlsx 打开（可能已损坏，或实际是 .xls 老格式，请另存为 .xlsx）。');
        }

        try {
            $shared = self::readSharedStrings($zip);
            $sheets = self::readSheetEntries($zip);
            if ($sheets === []) {
                throw new RuntimeException('xlsx 中没有找到任何工作表。');
            }

            $sheetName = '';
            $sheetPath = '';
            if ($preferredSheet !== '' && isset($sheets[$preferredSheet])) {
                $sheetName = $preferredSheet;
                $sheetPath = $sheets[$preferredSheet];
            } else {
                $first     = array_key_first($sheets);
                $sheetName = (string) $first;
                $sheetPath = (string) $sheets[$first];
            }

            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new RuntimeException('工作表内容读取失败（' . $sheetPath . '）。');
            }

            return [
                'sheet' => $sheetName,
                'rows'  => self::parseSheet((string) $xml, $shared),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * 工作表清单：名称 → zip 内路径。
     *
     * @return array<string,string>
     */
    public static function sheetNames(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            return [];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        try {
            return array_keys(self::readSheetEntries($zip));
        } finally {
            $zip->close();
        }
    }

    // =========================================================
    // 内部解析
    // =========================================================

    /**
     * @return array<int,string>
     */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = self::loadXml((string) $xml);
        $out = [];
        foreach ($doc->getElementsByTagNameNS('*', 'si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagNameNS('*', 't') as $t) {
                $text .= (string) $t->textContent;
            }
            $out[] = $text;
        }
        return $out;
    }

    /**
     * @return array<string,string> 表名 → zip 内路径
     */
    private static function readSheetEntries(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false) {
            return [];
        }

        $relMap = [];
        if ($rels !== false) {
            $relDoc = self::loadXml((string) $rels);
            foreach ($relDoc->getElementsByTagNameNS('*', 'Relationship') as $rel) {
                if (!$rel instanceof DOMElement) {
                    continue;
                }
                $id     = (string) $rel->getAttribute('Id');
                $target = (string) $rel->getAttribute('Target');
                if ($id !== '' && $target !== '') {
                    $relMap[$id] = $target;
                }
            }
        }

        $doc    = self::loadXml((string) $workbook);
        $sheets = [];
        foreach ($doc->getElementsByTagNameNS('*', 'sheet') as $sheet) {
            if (!$sheet instanceof DOMElement) {
                continue;
            }
            $name = (string) $sheet->getAttribute('name');
            $rid  = (string) $sheet->getAttributeNS(self::NS_REL, 'id');
            if ($name === '' || $rid === '' || !isset($relMap[$rid])) {
                continue;
            }

            $target = ltrim($relMap[$rid], '/');
            if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
            }
            $sheets[$name] = $target;
        }

        return $sheets;
    }

    /**
     * 解析 worksheet XML。
     *
     * @param array<int,string> $shared
     * @return array<int,array<int,string>>
     */
    private static function parseSheet(string $xml, array $shared): array
    {
        $doc  = self::loadXml($xml);
        $rows = [];

        foreach ($doc->getElementsByTagNameNS('*', 'row') as $rowNode) {
            $row = [];
            foreach ($rowNode->childNodes as $cell) {
                if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                    continue;
                }

                $ref  = (string) $cell->getAttribute('r');
                $type = (string) $cell->getAttribute('t');
                $index = self::columnIndex($ref);
                if ($index < 0) {
                    $index = count($row);
                }

                $value = '';
                if ($type === 's') {
                    $v       = $cell->getElementsByTagNameNS('*', 'v')->item(0);
                    $sharedI = $v !== null ? (int) $v->textContent : -1;
                    $value   = $shared[$sharedI] ?? '';
                } elseif ($type === 'inlineStr') {
                    foreach ($cell->getElementsByTagNameNS('*', 't') as $t) {
                        $value .= (string) $t->textContent;
                    }
                } else {
                    $v = $cell->getElementsByTagNameNS('*', 'v')->item(0);
                    if ($v !== null) {
                        $value = (string) $v->textContent;
                    }
                }

                $row[$index] = trim($value);
            }

            if ($row === []) {
                continue;
            }

            // 补齐空洞，保证列索引连续
            $max = max(array_keys($row));
            $dense = [];
            for ($i = 0; $i <= $max; $i++) {
                $dense[$i] = $row[$i] ?? '';
            }
            $rows[] = $dense;
        }

        return $rows;
    }

    /**
     * 单元格引用（如 "BC12"）→ 列索引（从 0 开始），失败返回 -1。
     */
    private static function columnIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return -1;
        }

        $index = 0;
        $len   = strlen($m[1]);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($m[1][$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * 安全加载 XML（LIBXML_NONET 禁止网络实体，不启用 NOENT 以防实体展开攻击）。
     */
    private static function loadXml(string $xml): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            throw new RuntimeException('Excel 内部 XML 解析失败，文件可能已损坏。');
        }
        return $doc;
    }
}
