<?php
/**
 * 幂等迁移：补齐检索同义词（2026-10-24）。
 *
 * 为什么（依据 search_logs 真实零结果词，不是拍脑袋加的）：
 *   1) 「MSQ-200」被搜过 1 次、「扫码枪 MSQ-200 故障」整句被搜过 2 次，全部 0 结果。
 *      库里没有任何字段写这个型号（cases.device 只写了「扫码枪乱码」），
 *      但确实存在扫码枪相关案例（#2）。把型号挂到扫码枪组下，
 *      搜「MSQ-200」即可带出扫码枪案例 —— 不改任何案例数据，只补词表。
 *   2) 「绎线」被搜过 2 次 0 结果，是「绞线」的错别字（绞线有 5 条）。
 *   3) 「扫戏枪」被搜过 1 次 0 结果，是「扫码枪」的输入错误。
 *
 * 追加而非覆盖：先读现有配置，只补缺失的主词行，用户在后台手工加的词不会被冲掉。
 *
 * 安全性：可重复执行（已存在的主词会跳过）。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 检索同义词补齐 ==\n";

// 每行一组：主词=别名1,别名2
$additions = [
    'MSQ-200=扫码枪,MSQ200,msq200',
    '绞线=绎线',
    '扫戏枪=扫码枪',
];

$current = (string) Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => 'search.synonyms']);
echo "现有配置：\n" . ($current === '' ? "  (空)\n" : $current . "\n");

// 现有主词集合（行首到 = 之前），用于幂等判断
$existingHeads = [];
foreach (preg_split('/\r\n|\r|\n/', $current) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
        continue;
    }
    $existingHeads[trim(explode('=', $line, 2)[0])] = true;
}

$appended = [];
foreach ($additions as $line) {
    $head = trim(explode('=', $line, 2)[0]);
    if (isset($existingHeads[$head])) {
        echo "[skip] 主词已存在：{$head}\n";
        continue;
    }
    $appended[] = $line;
    echo "[add ] {$line}\n";
}

if ($appended === []) {
    echo "== 无需变更 ==\n";
    return;
}

$newValue = $current === '' ? implode("\n", $appended) : rtrim($current, "\r\n") . "\n" . implode("\n", $appended);

$exists = (int) Database::scalar('SELECT COUNT(*) FROM settings WHERE skey = :k', ['k' => 'search.synonyms']);
if ($exists > 0) {
    Database::execute(
        'UPDATE settings SET svalue = :v, updated_at = :t WHERE skey = :k',
        ['v' => $newValue, 't' => now(), 'k' => 'search.synonyms']
    );
    echo "[update] search.synonyms 已更新\n";
} else {
    Database::insert('settings', ['skey' => 'search.synonyms', 'svalue' => $newValue, 'updated_at' => now()]);
    echo "[insert] search.synonyms 已写入\n";
}

echo "新配置：\n" . $newValue . "\n";
echo "解析组数：" . \App\Models\Synonym::count() . "\n";
echo "== 迁移完成 ==\n";
