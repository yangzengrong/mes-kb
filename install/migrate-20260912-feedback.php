<?php
/**
 * 幂等迁移：员工反馈模块（2026-09-12）。
 *
 * 1) 建 feedbacks 表（员工提交的 MES 疑难问题）
 * 2) attachments 增加 feedback_id 列（反馈附件，转案例后自动归属案例）
 * 3) 预置系统配置：开关、部门清单、每小时提交上限
 *
 * 安全性：可重复执行，已存在结构/数据自动跳过。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 员工反馈模块迁移 ==\n";

// ---------- 1) feedbacks 表 ----------
$createSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `feedbacks` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(24)  NOT NULL COMMENT '反馈单号，如 F260912-0007',
  `employee_name` VARCHAR(50)  NOT NULL COMMENT '员工姓名',
  `department`    VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '归属部门',
  `employee_no`   VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '工号',
  `contact`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '联系方式（分机/手机）',
  `process_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发生工序（processes.id，0=未指定）',
  `device`        VARCHAR(120) NOT NULL DEFAULT '' COMMENT '涉及设备 / MES 模块',
  `title`         VARCHAR(200) NOT NULL COMMENT '问题标题',
  `content`       TEXT         NOT NULL COMMENT '问题详细描述',
  `urgency`       VARCHAR(20)  NOT NULL DEFAULT 'normal' COMMENT '紧急程度：low/normal/high/urgent',
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending待受理/processing处理中/resolved已解决/closed已关闭',
  `case_id`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '转成的故障案例 ID',
  `handler_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '处理人（后台账号）',
  `handled_at`    DATETIME     DEFAULT NULL COMMENT '处理时间',
  `admin_note`    TEXT         COMMENT '管理员处理结论 / 关闭原因（员工可查）',
  `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
  `deleted_at`    DATETIME     DEFAULT NULL COMMENT '软删除标记',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_process` (`process_id`),
  KEY `idx_case` (`case_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='员工反馈表（MES 疑难问题上报）'
SQL;
Database::execute($createSql);
echo "[1] feedbacks 表就绪\n";

// ---------- 2) attachments.feedback_id 列 ----------
$hasColumn = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'feedback_id'"
);
if ($hasColumn === 0) {
    Database::execute('ALTER TABLE `attachments` ADD COLUMN `feedback_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'所属反馈（feedbacks.id，0=非反馈附件）\' AFTER `case_id`');
    Database::execute('ALTER TABLE `attachments` ADD KEY `idx_feedback` (`feedback_id`)');
    echo "[2] attachments.feedback_id 列已添加\n";
} else {
    echo "[2] attachments.feedback_id 列已存在，跳过\n";
}

// ---------- 3) 预置系统配置 ----------
$defaults = [
    'feedback.enabled'       => '1',
    'feedback.departments'   => "行政综合科\n制造科\n业务科\n销售科\n品保科\n物控科\n仓库\n财务科",
    'feedback.limit_per_hour' => '5',
    'feedback.tip'           => '遇到 MES 系统疑难问题？在这里提交，运维收到后会给出解决方案并沉淀到知识库。',
];
$seeded = 0;
foreach ($defaults as $key => $value) {
    $exists = (int) Database::scalar('SELECT COUNT(*) FROM settings WHERE skey = :k', ['k' => $key]);
    if ($exists > 0) {
        continue;
    }
    Database::insert('settings', ['skey' => $key, 'svalue' => $value, 'updated_at' => now()]);
    $seeded++;
}
echo "[3] 预置配置写入 {$seeded} 条（其余已存在跳过）\n";

echo "== 迁移完成 ==\n";
