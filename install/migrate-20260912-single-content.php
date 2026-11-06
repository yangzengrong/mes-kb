<?php
declare(strict_types=1);

/**
 * 单编辑器改造迁移（2026-09-12）：
 * 后台表单已合并为一个整体富文本编辑器，内容统一存 cases.phenomenon。
 * 本脚本把历史案例分散在 cause / solution / prevention 的内容
 * 合并进 phenomenon（带 h4 小标题，不丢任何数据），并将三个字段清空。
 *
 * 幂等：合并完成后三字段为空，重复执行自动跳过。
 * 执行方式：php migrate-20260912-single-content.php（CLI，不走 Web）
 */

$baseDir = dirname(__DIR__);
require $baseDir . '/config/config.php';
require $baseDir . '/app/bootstrap.php';

use App\Core\Database;

echo "== 单编辑器内容合并迁移 ==\n";

// ---------- 0) phenomenon 列升级为 MEDIUMTEXT（整体正文容量，幂等） ----------
$colType = Database::selectOne(
    "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'phenomenon'",
    []
);
if ($colType !== null && strtolower((string) $colType['DATA_TYPE']) !== 'mediumtext') {
    $ddl = 'ALTER TABLE `cases` MODIFY `phenomenon` MEDIUMTEXT COMMENT \'正文内容（单编辑器整体内容）\'';
    Database::execute($ddl, []);
    echo "[0] phenomenon 列已升级为 MEDIUMTEXT\n";
} else {
    echo "[0] phenomenon 列已是 MEDIUMTEXT，跳过\n";
}

$rows = Database::select(
    'SELECT id, doc_type, phenomenon, cause, solution, prevention FROM cases',
    []
);

$labels = [
    'case'      => ['phenomenon' => '故障现象', 'cause' => '故障原因', 'solution' => '解决方案', 'prevention' => '预防措施'],
    'manual'    => ['phenomenon' => '操作步骤', 'cause' => '前提条件', 'solution' => '关键操作说明', 'prevention' => '注意事项'],
    'changelog' => ['phenomenon' => '更新内容', 'cause' => '更新背景', 'solution' => '更新后的变化', 'prevention' => '影响范围'],
];

$mergedCount = 0;
$skippedCount = 0;

foreach ($rows as $row) {
    $cause      = trim((string) $row['cause']);
    $solution   = trim((string) $row['solution']);
    $prevention = trim((string) $row['prevention']);

    // 三个附加字段全空 → 无需合并（幂等跳过点）
    if ($cause === '' && $solution === '' && $prevention === '') {
        $skippedCount++;
        continue;
    }

    $docType = (string) $row['doc_type'];
    $map     = $labels[$docType] ?? $labels['case'];

    $parts = [];
    $phenomenon = trim((string) $row['phenomenon']);
    if ($phenomenon !== '') {
        // 已有正文：若不含小标题则补一个首节标题，保证合并后结构清晰
        if (stripos($phenomenon, '<h4') === false) {
            $parts[] = '<h4>' . $map['phenomenon'] . '</h4>' . $phenomenon;
        } else {
            $parts[] = $phenomenon;
        }
    }
    foreach (['cause' => $cause, 'solution' => $solution, 'prevention' => $prevention] as $key => $value) {
        if ($value !== '') {
            $parts[] = '<h4>' . $map[$key] . '</h4>' . $value;
        }
    }

    $merged = implode("\n", $parts);

    Database::execute(
        'UPDATE cases SET phenomenon = :content, cause = \'\', solution = \'\', prevention = \'\' WHERE id = :id',
        ['content' => $merged, 'id' => (int) $row['id']]
    );
    $mergedCount++;
    echo "  合并案例 #{$row['id']}（{$docType}）：" . mb_strlen($merged) . " 字符\n";
}

echo "完成：合并 {$mergedCount} 条，跳过 {$skippedCount} 条（已是单内容结构）。\n";
echo "迁移成功标记：SINGLE-CONTENT-MIGRATION-DONE\n";
