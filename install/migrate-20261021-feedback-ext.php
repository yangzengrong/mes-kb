<?php
/**
 * 幂等迁移：员工反馈表扩展字段（2026-10-21）。
 *
 * 依据公司原有 Excel 人工台账（MES系统待处理问题持续记录）的表头，
 * 把对员工有价值、原系统缺失的信息补进反馈表：
 *   1) related_department 相关部门（涉及/需协同的部门，如财务、仓库、研发）
 *   2) expected_effect    期望达到的效果（员工期望系统达成的目标，利于排优先级）
 *   3) related_module     关联表单（界面）（出问题的 MES 屏幕/模块，如"品质检验参数界面"）
 *   4) completed_at       完成时间（Excel 原有"预计完成/预计变更/实际完成"三个时间列，
 *                          只保留一个"完成时间"：反馈置为「已解决」时由系统自动记录）
 *
 * 安全性：可重复执行，列已存在则跳过。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 员工反馈表扩展字段迁移 ==\n";

$table = 'feedbacks';
$addColumns = [
    'related_department' => "VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '相关部门（需协同处理的部门）' AFTER `department`",
    'expected_effect'    => "TEXT          NOT NULL                COMMENT '期望达到的效果'            AFTER `content`",
    'related_module'     => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '关联表单（界面）/ MES 模块'   AFTER `device`",
    'completed_at'       => "DATETIME      DEFAULT NULL                 COMMENT '完成时间（置为已解决时自动记录）' AFTER `updated_at`",
];

foreach ($addColumns as $column => $def) {
    $exists = (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => $table, 'c' => $column]
    );
    if ($exists > 0) {
        echo "[skip] 列 {$column} 已存在\n";
        continue;
    }
    Database::execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$def}");
    echo "[add]  列 {$column} 已添加\n";
}

// 回填：历史已解决记录补上完成时间（取处理时间），未解决留空
$backfilled = (int) Database::execute(
    "UPDATE `{$table}` SET `completed_at` = `handled_at`
     WHERE `status` = 'resolved' AND `completed_at` IS NULL AND `handled_at` IS NOT NULL"
);
if ($backfilled > 0) {
    echo "[backfill] 已为 {$backfilled} 条历史已解决记录回填完成时间\n";
}

echo "== 迁移完成 ==\n";
