<?php
/**
 * 幂等迁移：归属部门改为固定科室枚举 + 停用「相关部门」字段（2026-10-21）。
 *
 * 为什么：
 *   1) 用户指定归属部门为固定 8 个科室（原为自由文本 + datalist 候选，口径不统一），
 *      前台改为严格下拉，故把 feedback.departments 配置更新为该枚举。
 *      后台「系统配置 → 归属部门候选」仍可维护（每行一个），改配置即改下拉项。
 *   2) 「相关部门」字段取消（表单/进度查询/后台详情/导出均不再使用）。
 *      数据库列 related_department 保留不删 —— 历史数据留档，避免破坏性变更。
 *
 * 安全性：可重复执行。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 反馈部门枚举固化 + 相关部门字段停用 ==\n";

// ---------- 1) 归属部门枚举（用户指定的 8 个科室） ----------
$departments = "行政综合科\n制造科\n业务科\n销售科\n品保科\n物控科\n仓库\n财务科";

$exists = (int) Database::scalar('SELECT COUNT(*) FROM settings WHERE skey = :k', ['k' => 'feedback.departments']);
if ($exists > 0) {
    Database::execute(
        'UPDATE settings SET svalue = :v, updated_at = :t WHERE skey = :k',
        ['v' => $departments, 't' => now(), 'k' => 'feedback.departments']
    );
    echo "[update] feedback.departments 已更新为 8 个科室\n";
} else {
    Database::insert('settings', ['skey' => 'feedback.departments', 'svalue' => $departments, 'updated_at' => now()]);
    echo "[insert] feedback.departments 已写入 8 个科室\n";
}

// ---------- 2) related_department 停用说明 ----------
// 不做 DDL：列保留存历史数据；应用层（前台表单/进度查询、后台详情/列表、CSV 导出）已全部移除。
echo "[note] related_department 已停用（列保留，历史数据留档）\n";

echo "== 迁移完成 ==\n";
