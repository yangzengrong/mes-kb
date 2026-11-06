-- =============================================================
-- MES系统知识库 —— 数据库结构
-- 适配 MySQL 5.7 / 8.0，字符集 utf8mb4，存储引擎 InnoDB
-- ©Yangzengrong 版权所有
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- 用户表（需求 4.1 用户权限管理）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(50)  NOT NULL COMMENT '登录账号',
  `password_hash`    VARCHAR(255) NOT NULL COMMENT '密码哈希（password_hash）',
  `real_name`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '姓名',
  `role`             VARCHAR(20)  NOT NULL DEFAULT 'guest' COMMENT '角色：super_admin/op_admin/guest',
  `status`           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `login_fail_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `locked_until`     DATETIME     DEFAULT NULL COMMENT '锁定截止时间',
  `last_login_at`    DATETIME     DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip`    VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `remark`           VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统用户表';

-- -------------------------------------------------------------
-- 工序分类表（线缆专属改版：一级分类 = 9 大生产工序，可维护/启停/扩展）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL COMMENT '工序名称',
  `sort`       INT          NOT NULL DEFAULT 0 COMMENT '排序值，越小越前',
  `status`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='工序分类表（一级分类）';

-- 预置线缆生产 9 大工序（幂等：重复执行自动跳过）
INSERT IGNORE INTO `processes` (`name`, `sort`) VALUES
('绞线', 10), ('成缆', 20), ('绝缘', 30), ('护套', 40), ('数据缆', 50),
('编织', 60), ('自动供料', 70), ('实验室', 80), ('数据采集', 90),
('仓库', 95), ('标签', 96), ('其它', 100);

-- -------------------------------------------------------------
-- 岗位分类表（岗位操作手册维度：手册按责任岗位归档维护）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `positions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL COMMENT '岗位名称',
  `sort`       INT          NOT NULL DEFAULT 0 COMMENT '排序值，越小越前',
  `status`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='岗位分类表（岗位操作手册维度）';

-- MES 系统菜单权限明细（数据来源：《系统菜单明细MES系统权限.xlsx》按层级浏览，255 条）
CREATE TABLE IF NOT EXISTS `mes_menus` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module`    VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '一级菜单 / 所属系统（WMS、SFIS 等）',
  `menu_l2`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '二级菜单',
  `menu_l3`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '三级菜单',
  `menu_l4`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '四级菜单',
  `menu_name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '叶子菜单名（最深层级）',
  `menu_code` VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '菜单编码',
  `role_name` VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '角色权限（员工授权依据）',
  `url`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '功能页面地址',
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`),
  KEY `idx_role` (`role_name`),
  KEY `idx_menu_name` (`menu_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='MES系统菜单权限明细表';

-- -------------------------------------------------------------
-- 车间设备采集 IP 地址表（2026-10-23 新增）
-- 数据来源：MES 开发商《车间设备采集IP地址.xlsx》（PLC / 工控机 / 传感器等）
-- 用途：设备损坏更换后，按设备名或 IP 反查历史内网地址；支持后台维护与前台报备
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `device_ips` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_name` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '设备名称',
  `ip_address`  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'IP 地址（内网）',
  `device_type` VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '设备类型：PLC/工控机/触摸屏/传感器/其他',
  `process_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联工序（processes.id，0=未绑定/辅助设备）',
  `custom_process` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '自定义关联工序名称（process_id=0 时生效，如新采购设备的产线）',
  `status`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1在用 0已更换(历史) 2待审核',
  `remark`      VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注（源表第三列，多为登记日期或说明）',
  `source`      VARCHAR(20)  NOT NULL DEFAULT 'import' COMMENT '来源：import导入/manual后台维护/submit前台报备',
  `submit_ip`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '前台报备人 IP',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_device_name` (`device_name`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_device_type` (`device_type`),
  KEY `idx_status` (`status`),
  KEY `idx_process_id` (`process_id`),
  KEY `idx_custom_process` (`custom_process`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='车间设备采集IP地址表';

-- 预置 9 大岗位（幂等：重复执行自动跳过）
INSERT IGNORE INTO `positions` (`name`, `sort`) VALUES
('销售', 10), ('研发', 20), ('质量', 30), ('物控', 40), ('生产', 50),
('仓库', 60), ('技术', 70), ('设备', 80), ('实验室', 90);

-- -------------------------------------------------------------
-- 故障分类表（需求 4.2 故障案例分类管理，支持一级/二级）
-- 改版后降级为二级「故障类型」筛选维度，数据结构保持不变
-- -------------------------------------------------------------
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0为一级分类',
  `name`       VARCHAR(60)  NOT NULL COMMENT '分类名称',
  `sort`       INT          NOT NULL DEFAULT 0 COMMENT '排序值，越小越前',
  `status`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent_sort` (`parent_id`, `sort`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='故障案例分类表';

-- -------------------------------------------------------------
-- 故障案例表（需求 4.3 故障案例全生命周期管理）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cases` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(200) NOT NULL COMMENT '故障标题',
  `process_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属工序（processes.id，0=未绑定，历史兼容）',
  `position_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '责任/匹配岗位（positions.id，系统操作手册可选，0=通用不限岗位）',
  `doc_type`     VARCHAR(20)  NOT NULL DEFAULT 'case' COMMENT '文档类型：case故障案例/manual系统操作手册/changelog MES版本更新日志',
  `category_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属分类（改版后为二级「故障类型」）',
  `device`       VARCHAR(120) NOT NULL DEFAULT '' COMMENT '故障设备/模块',
  `error_code`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '报错代码',
  `tags`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '标签关键字（英文逗号分隔，后台手动输入，前台可按标签搜索）',
  `phenomenon`   MEDIUMTEXT   COMMENT '正文内容（单编辑器整体内容）',
  `cause`        TEXT         COMMENT '故障原因',
  `solution`     MEDIUMTEXT   COMMENT '解决方案',
  `prevention`   TEXT         COMMENT '预防措施',
  `handler`      VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '处理人',
  `handled_at`   DATETIME     DEFAULT NULL COMMENT '处理时间',
  `status`       VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending待审核/published已发布/offline已下架/expired已过期',
  `views`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
  `downloads`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '附件下载次数',
  `is_top`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否置顶',
  `author_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `version_no`   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '当前版本号',
  `reviewed_by`  INT UNSIGNED DEFAULT NULL COMMENT '审核人',
  `reviewed_at`  DATETIME     DEFAULT NULL COMMENT '审核时间',
  `review_note`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '审核意见',
  `published_at` DATETIME     DEFAULT NULL COMMENT '首次发布时间',
  `expired_at`   DATETIME     DEFAULT NULL COMMENT '过期时间',
  `deleted_at`   DATETIME     DEFAULT NULL COMMENT '回收站标记，非空即已删除',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_deleted` (`status`, `deleted_at`),
  KEY `idx_category` (`category_id`),
  KEY `idx_process` (`process_id`),
  KEY `idx_doc_type` (`doc_type`),
  KEY `idx_author` (`author_id`),
  KEY `idx_views` (`views`),
  KEY `idx_published_at` (`published_at`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='MES故障案例表';

-- -------------------------------------------------------------
-- 案例版本记录表（需求 4.3 保留版本更新记录，可追溯）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `case_versions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`     INT UNSIGNED NOT NULL,
  `version_no`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本号',
  `snapshot`    LONGTEXT     COMMENT '该版本内容快照（JSON）',
  `change_note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '变更说明',
  `editor_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '编辑人',
  `editor_name` VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '编辑人账号',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_case_version` (`case_id`, `version_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='案例版本记录表';

-- -------------------------------------------------------------
-- 附件表（需求 4.4 附件文件精细化管理）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0表示未绑定案例',
  `feedback_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属员工反馈（feedbacks.id，0=非反馈附件）',
  `orig_name`      VARCHAR(200) NOT NULL COMMENT '原始文件名',
  `stored_path`    VARCHAR(255) NOT NULL COMMENT '相对 storage 的存储路径',
  `ext`            VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '扩展名',
  `mime`           VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'MIME 类型',
  `size`           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '字节数',
  `uploader_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `deleted_at`     DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_case` (`case_id`),
  KEY `idx_feedback` (`feedback_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='案例附件表';

-- -------------------------------------------------------------
-- 员工反馈表（员工上报 MES 疑难问题，后台受理后生成故障案例）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feedbacks` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(24)  NOT NULL COMMENT '反馈单号，如 F260912-0007',
  `employee_name` VARCHAR(50)  NOT NULL COMMENT '员工姓名',
  `department`    VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '归属部门',
  `related_department` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '相关部门（需协同处理的部门）',
  `employee_no`   VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '工号',
  `contact`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '联系方式（分机/手机）',
  `process_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发生工序（processes.id，0=未指定）',
  `category_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '员工自选问题类型（categories.id，0=未选）',
  `device`        VARCHAR(120) NOT NULL DEFAULT '' COMMENT '涉及设备 / MES 模块',
  `related_module` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '关联表单（界面）/ MES 模块',
  `title`         VARCHAR(200) NOT NULL COMMENT '问题标题',
  `content`       TEXT         NOT NULL COMMENT '问题详细描述',
  `expected_effect` TEXT        NOT NULL COMMENT '期望达到的效果',
  `urgency`       VARCHAR(20)  NOT NULL DEFAULT 'normal' COMMENT '紧急程度：low/normal/high/urgent',
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending待受理/processing处理中/resolved已解决/closed已关闭',
  `case_id`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '转成的故障案例 ID',
  `handler_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '处理人（后台账号）',
  `handled_at`    DATETIME     DEFAULT NULL COMMENT '处理时间',
  `completed_at`  DATETIME     DEFAULT NULL COMMENT '完成时间（置为已解决时自动记录；对应旧台账"实际完成时间"）',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='员工反馈表（MES 疑难问题上报）';

-- -------------------------------------------------------------
-- 管理员操作日志（需求 4.6）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `operation_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `username`    VARCHAR(50)  NOT NULL DEFAULT '',
  `action`      VARCHAR(60)  NOT NULL COMMENT '操作标识，如 case.create',
  `target_type` VARCHAR(30)  DEFAULT NULL COMMENT '对象类型',
  `target_id`   VARCHAR(30)  DEFAULT NULL COMMENT '对象ID',
  `detail`      VARCHAR(500) NOT NULL DEFAULT '' COMMENT '操作内容',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员操作日志';

-- -------------------------------------------------------------
-- 用户访问日志（需求 4.6）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `access_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(30)  NOT NULL COMMENT 'view/download/preview',
  `extra`      VARCHAR(255) NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `url`        VARCHAR(255) NOT NULL DEFAULT '',
  `referer`    VARCHAR(255) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_case` (`case_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户访问日志';

-- -------------------------------------------------------------
-- 检索关键词日志（需求 4.5 检索数据统计）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `search_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `keyword`      VARCHAR(100) NOT NULL,
  `result_count` INT          NOT NULL DEFAULT 0,
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_keyword` (`keyword`),
  KEY `idx_result` (`result_count`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='检索关键词日志';

-- -------------------------------------------------------------
-- 登录尝试记录（需求 4.7 防暴力破解）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(50)  NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `success`    TINYINT(1)   NOT NULL DEFAULT 0,
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_created` (`ip`, `created_at`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='后台登录尝试记录';

-- -------------------------------------------------------------
-- 系统配置表（需求 4.9）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `skey`       VARCHAR(60)  NOT NULL,
  `svalue`     TEXT         NOT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统配置表';

SET FOREIGN_KEY_CHECKS = 1;
