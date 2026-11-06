<?php
/**
 * 幂等迁移：反馈页横幅广告位配置（2026-10-21）。
 *
 * 为什么：
 *   原「员工反馈」页有两段语义重叠的引导语（标题右侧一行 + 表单上方一行），
 *   分散注意力。改为标题与表单之间一个居中的横幅位，承载标题 + 描述两行，
 *   后台「系统配置 → 员工反馈」可随时改文案，两项均留空即整幅不显示。
 *   前台原先那两处引导语同步移除（视图硬编码行 + feedback.tip 展示），全页引导只此一处。
 *
 * 安全性：可重复执行，键已存在则跳过。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 反馈页横幅广告位配置 ==\n";

// 描述优先继承原「反馈页提示语」的当前值（用户可能改过），没有再给出厂文案
$legacyTip = trim((string) Database::scalar(
    'SELECT svalue FROM settings WHERE skey = :k',
    ['k' => 'feedback.tip']
));
$defaults = [
    'feedback.banner_title' => '把你在 MES 里遇到的疑难问题告诉我们。填得越具体，运维定位越快；处理结论会沉淀进知识库。',
    'feedback.banner_desc'  => $legacyTip !== ''
        ? $legacyTip
        : '遇到 MES 系统疑难问题？在这里提交，运维收到后会给出解决方案并沉淀到知识库。',
];

$seeded = 0;
foreach ($defaults as $key => $value) {
    $exists = (int) Database::scalar('SELECT COUNT(*) FROM settings WHERE skey = :k', ['k' => $key]);
    if ($exists > 0) {
        echo "[skip] {$key} 已存在\n";
        continue;
    }
    Database::insert('settings', ['skey' => $key, 'svalue' => $value, 'updated_at' => now()]);
    $seeded++;
    echo "[add]  {$key}\n";
}

echo "写入 {$seeded} 条。横幅在后台「系统配置 → 员工反馈」可改可关（两栏均留空即不展示）。\n";
echo "== 迁移完成 ==\n";
