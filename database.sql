-- ============================================
-- 班级积分管理系统 · MySQL 8.0 建表脚本喵
-- 兼容 MySQL 5.7+（CHECK 约束在 5.7 会被解析但忽略喵）
-- 本脚本只建表不建库喵：数据库由一体式安装向导自动创建
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 年级表
DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '年级ID',
  `name` varchar(50) NOT NULL COMMENT '年级名称',
  CONSTRAINT `chk_grades_name` CHECK (CHAR_LENGTH(`name`) > 0),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 班级表
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '班级ID',
  `grade_id` int NOT NULL COMMENT '所属年级',
  `name` varchar(50) NOT NULL COMMENT '班级名称',
  `class_leader` varchar(50) DEFAULT NULL COMMENT '负责人',
  `is_frozen` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否冻结',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  CONSTRAINT `chk_classes_name` CHECK (CHAR_LENGTH(`name`) > 0),
  PRIMARY KEY (`id`),
  KEY `idx_grade_name` (`grade_id`, `name`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 管理员表
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '管理员ID',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password_hash` varchar(255) NOT NULL COMMENT '密码哈希',
  `role` enum('super_admin','system_admin','score_admin') NOT NULL DEFAULT 'score_admin' COMMENT '角色',
  `grade_id` int DEFAULT NULL COMMENT '年级ID（保留字段）',
  `class_id` int DEFAULT NULL COMMENT '班级ID（保留字段）',
  `security_question` varchar(255) DEFAULT NULL COMMENT '密保问题',
  `security_answer_hash` varchar(255) DEFAULT NULL COMMENT '密保答案哈希',
  `totp_secret` varchar(64) DEFAULT NULL COMMENT 'TOTP密钥',
  `failed_attempts` int NOT NULL DEFAULT 0 COMMENT '失败登录次数',
  `lock_until` datetime DEFAULT NULL COMMENT '锁定截止时间',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  CONSTRAINT `chk_admins_username` CHECK (CHAR_LENGTH(`username`) > 0),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 学期表
DROP TABLE IF EXISTS `semesters`;
CREATE TABLE `semesters` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '学期ID',
  `name` varchar(50) NOT NULL COMMENT '学期名称',
  `start_date` date NOT NULL COMMENT '开始日期',
  `end_date` date NOT NULL COMMENT '结束日期',
  `is_current` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否当前学期',
  CONSTRAINT `chk_semesters_date` CHECK (`end_date` >= `start_date`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 奖惩类型表
DROP TABLE IF EXISTS `reward_punish_types`;
CREATE TABLE `reward_punish_types` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '类型ID',
  `name` varchar(100) NOT NULL COMMENT '奖惩名称',
  `type` enum('reward','punish') NOT NULL COMMENT '分类',
  `category` varchar(50) DEFAULT NULL COMMENT '子分类（如：卫生、纪律）',
  `default_points` decimal(5,2) NOT NULL COMMENT '默认分值',
  `is_builtin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否内置（不可删除）',
  CONSTRAINT `chk_rpt_points` CHECK (`default_points` <> 0),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 积分记录表
DROP TABLE IF EXISTS `score_records`;
CREATE TABLE `score_records` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `class_id` int NOT NULL COMMENT '班级ID',
  `type_id` int NOT NULL COMMENT '奖惩类型ID',
  `points` decimal(5,2) NOT NULL COMMENT '实际分值',
  `admin_id` int NOT NULL COMMENT '操作管理员ID',
  `note` text COMMENT '备注',
  `image_path` varchar(255) DEFAULT NULL COMMENT '截图路径',
  `semester_id` int NOT NULL COMMENT '学期ID',
  `week_number` int DEFAULT NULL COMMENT '学期内周次',
  `month_number` int DEFAULT NULL COMMENT '学期内月次',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_semester_class_time` (`semester_id`, `class_id`, `created_at`),
  KEY `type_id` (`type_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `score_records_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `score_records_ibfk_2` FOREIGN KEY (`type_id`) REFERENCES `reward_punish_types` (`id`),
  CONSTRAINT `score_records_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`),
  CONSTRAINT `score_records_ibfk_4` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 操作日志表
DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE `admin_logs` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `admin_id` int NOT NULL COMMENT '管理员ID',
  `action` varchar(50) NOT NULL COMMENT '操作',
  `target_type` varchar(30) DEFAULT NULL COMMENT '目标类型',
  `target_id` int DEFAULT NULL COMMENT '目标ID',
  `details` text DEFAULT NULL COMMENT '操作详情',
  `ip` varchar(45) DEFAULT NULL COMMENT 'IP地址',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 迁移版本表
DROP TABLE IF EXISTS `db_migrations`;
CREATE TABLE `db_migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version` int NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `executed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 插入默认年级
INSERT INTO `grades` (`id`, `name`) VALUES
(1, '一年级'),
(2, '二年级'),
(3, '三年级'),
(4, '四年级'),
(5, '五年级'),
(6, '六年级');

-- 插入内置奖惩类型
INSERT INTO `reward_punish_types` (`name`, `type`, `category`, `default_points`, `is_builtin`) VALUES
('卫生不达标', 'punish', '卫生', -2.00, 1),
('纪律差', 'punish', '纪律', -1.00, 1),
('迟到', 'punish', '考勤', -1.00, 1),
('作业缺交', 'punish', '学习', -1.00, 1),
('仪表不规范', 'punish', '仪容', -0.50, 1),
('卫生优秀', 'reward', '卫生', 2.00, 1),
('纪律好', 'reward', '纪律', 1.00, 1),
('早读认真', 'reward', '学习', 1.00, 1),
('积极参与活动', 'reward', '文体', 1.50, 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 旧版本升级无需手动执行脚本喵
-- 系统会通过 db_migrations 版本化迁移自动完成喵
-- ============================================