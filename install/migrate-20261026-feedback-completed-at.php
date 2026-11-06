<?php
/**
 * 幂等迁移：补齐员工反馈「完成时间」completed_at（2026-10-26）。
 *
 * 背景：
 *   migrate-20261021-feedback-ext.php 只加列并回填了一次历史数据，
 *   但 Feedback::updateStatus()/linkCase() 后续只写 handled_at，未写 completed_at，
 *   导致迁移之后新处理的「已解决」反馈在后台列表「完成时间」列显示「—」。
 *   代码侧已修复（置为已解决/已关闭时写入 completed_at），本脚本负责补齐存量数据。
 *
 * 口径：已解决 / 已关闭 且 completed_at 为空、handled_at 有值 → completed_at = handled_at。
 * 安全性：只更新 completed_at 为空的记录，可重复执行；第二次运行影响 0 行。
 * 执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 反馈完成时间回填（2026-10-26）==\n";

$before = (int) Database::scalar(
    "SELECT COUNT(*) FROM feedbacks
     WHERE status IN ('resolved','closed') AND completed_at IS NULL AND handled_at IS NOT NULL"
);
echo "[scan] 待回填 {$before} 条\n";

$affected = (int) Database::execute(
    "UPDATE feedbacks SET completed_at = handled_at
     WHERE status IN ('resolved','closed') AND completed_at IS NULL AND handled_at IS NOT NULL"
);
echo "[backfill] 已回填 {$affected} 条\n";

$remain = (int) Database::scalar(
    "SELECT COUNT(*) FROM feedbacks
     WHERE status IN ('resolved','closed') AND completed_at IS NULL"
);
echo "[check] 仍缺完成时间 {$remain} 条（若 >0，多为 handled_at 也为空的异常记录，需人工核对）\n";

echo "== 完成 ==\n";
