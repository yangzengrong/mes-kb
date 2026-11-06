<?php
declare(strict_types=1);

/**
 * 幂等迁移：系统操作手册体系（2026-09-14）。
 *
 * 背景：原「岗位操作手册」按 10 个岗位分板块展示，后台新增工序/岗位后首页导航与
 * 卡片区持续膨胀。现统一收敛为「系统操作手册」单一入口：
 *   1) doc_type = manual 的语义由「岗位操作手册」改为「系统操作手册」（值不变，仅含义与文案）
 *   2) cases.position_id 由「必绑责任岗位」改为「可选匹配岗位」（0 = 全岗位通用）
 *   3) tags 用于后期按关键字检索，本迁移不做改动（仅确认列存在与长度）
 *   4) 把历史上绑在「系统手册」岗位(id=10)的手册统一置为 0（通用），
 *      避免首页出现「系统手册」这个伪岗位；其余岗位绑定保留，作为匹配岗位
 *
 * 幂等：可重复执行。执行完请删除本文件。
 * 执行方式：php install/migrate-20260914-system-manual.php（CLI）
 */

$baseDir = dirname(__DIR__);
require $baseDir . '/config/config.php';
require $baseDir . '/app/bootstrap.php';

use App\Core\Database;

echo "== 系统操作手册体系迁移 ==\n";

// ---------- 0) 前置校验：cases.position_id 存在 ----------
$hasPosition = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'position_id'"
);
if ($hasPosition === 0) {
    echo "[!] cases.position_id 不存在，请先执行 migrate-20260912-position.php\n";
    exit(1);
}
echo "[0] cases.position_id 就绪\n";

// ---------- 1) 字段注释更新为「匹配岗位（可选）」 ----------
$col = Database::selectOne(
    "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'position_id'"
);
$currentComment = (string) ($col['COLUMN_COMMENT'] ?? '');
$targetComment  = '责任/匹配岗位（positions.id，系统操作手册可选，0=通用不限岗位）';
if ($currentComment !== $targetComment) {
    Database::execute(
        "ALTER TABLE `cases` MODIFY `position_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '" . $targetComment . "'"
    );
    echo "[1] position_id 注释已更新为「匹配岗位（可选）」\n";
} else {
    echo "[1] position_id 注释已是目标值，跳过\n";
}

// ---------- 2) doc_type 注释更新 ----------
$docCol = Database::selectOne(
    "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'doc_type'"
);
$docTarget = '文档类型：case故障案例/manual系统操作手册/changelog MES版本更新日志';
if ((string) ($docCol['COLUMN_COMMENT'] ?? '') !== $docTarget) {
    Database::execute(
        "ALTER TABLE `cases` MODIFY `doc_type` VARCHAR(20) NOT NULL DEFAULT 'case' COMMENT '" . $docTarget . "'"
    );
    echo "[2] doc_type 注释已更新\n";
} else {
    echo "[2] doc_type 注释已是目标值，跳过\n";
}

// ---------- 3) 「系统手册」岗位(10) 的手册统一置为通用(position_id=0) ----------
// 只在确有此岗位时才处理，避免误伤其它环境的 id 含义
$sysPos = Database::selectOne("SELECT id, name FROM positions WHERE name = '系统手册' LIMIT 1");
if ($sysPos !== null) {
    $sysPosId = (int) $sysPos['id'];

    // 先看影响范围（幂等：第二次执行影响行数为 0）
    $affected = (int) Database::scalar(
        "SELECT COUNT(*) FROM cases WHERE doc_type = 'manual' AND position_id = :pid",
        ['pid' => $sysPosId]
    );
    if ($affected > 0) {
        Database::execute(
            "UPDATE cases SET position_id = 0 WHERE doc_type = 'manual' AND position_id = :pid",
            ['pid' => $sysPosId]
        );
        echo "[3] 已将 {$affected} 篇原属「系统手册」岗位的手册置为通用（position_id=0）\n";
    } else {
        echo "[3] 无需要迁移的手册（已处理过或无绑定）\n";
    }

    // 停用该伪岗位，避免后台下拉里继续出现
    $stop = Database::execute(
        "UPDATE positions SET status = 0 WHERE id = :pid AND status = 1",
        ['pid' => $sysPosId]
    );
    echo $stop > 0
        ? "[4] 已停用「系统手册」岗位（历史行保留，不再出现在下拉）\n"
        : "[4] 「系统手册」岗位已是停用状态，跳过\n";
} else {
    echo "[3] 未发现名为「系统手册」的岗位，跳过\n";
}

// ---------- 4) 配置：首页最新手册条数（固定 5，写入设置便于后台调整） ----------
$exists = (int) Database::scalar("SELECT COUNT(*) FROM settings WHERE skey = 'home.manual_count'");
if ($exists === 0) {
    Database::insert('settings', [
        'skey'       => 'home.manual_count',
        'svalue'     => '5',
        'updated_at' => now(),
    ]);
    echo "[5] 新增配置 home.manual_count = 5（首页最新手册条数）\n";
} else {
    echo "[5] 配置 home.manual_count 已存在，跳过\n";
}

echo "== 迁移完成 ==\n";
echo "== 校验 ==\n";
$rows = Database::select("SELECT id, title, position_id, tags, status FROM cases WHERE doc_type = 'manual' ORDER BY id");
foreach ($rows as $row) {
    echo "  手册 #{$row['id']} position_id={$row['position_id']} status={$row['status']} tags={$row['tags']} 《{$row['title']}》\n";
}
echo "手册总数：" . Database::scalar("SELECT COUNT(*) FROM cases WHERE doc_type = 'manual' AND deleted_at IS NULL") . "\n";
echo "岗位总数（启用）：" . Database::scalar("SELECT COUNT(*) FROM positions WHERE status = 1") . "\n";
