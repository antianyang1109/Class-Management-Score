-- 创建数据库（请根据实际环境调整）
CREATE DATABASE IF NOT EXISTS `class_points` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `class_points`;

-- 年级表
CREATE TABLE `grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 班级表
CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `class_leader` varchar(50) DEFAULT NULL COMMENT '班级负责人',
  `is_frozen` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否冻结',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `grade_id` (`grade_id`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 管理员表
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','grade_admin','class_teacher') NOT NULL,
  `grade_id` int(11) DEFAULT NULL COMMENT '年级管理员/班主任所属年级',
  `class_id` int(11) DEFAULT NULL COMMENT '班主任所属班级',
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `security_question` varchar(255) DEFAULT NULL COMMENT '密保问题',
  `security_answer_hash` varchar(255) DEFAULT NULL COMMENT '密保答案哈希',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `grade_id` (`grade_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE SET NULL,
  CONSTRAINT `admins_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 学期表
CREATE TABLE `semesters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 奖惩类型表
CREATE TABLE `reward_punish_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('reward','punish') NOT NULL,
  `category` varchar(50) DEFAULT NULL COMMENT '分类（如：卫生、纪律）',
  `default_points` decimal(5,1) NOT NULL DEFAULT 0.0,
  `is_builtin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为内置类型',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 积分记录表
CREATE TABLE `score_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `points` decimal(5,1) NOT NULL COMMENT '实际使用的分值（可临时调整）',
  `admin_id` int(11) NOT NULL,
  `note` text,
  `image_path` varchar(255) DEFAULT NULL COMMENT '截图路径',
  `semester_id` int(11) NOT NULL,
  `week_number` int(11) NOT NULL COMMENT '学期内的周次',
  `month_number` int(11) NOT NULL COMMENT '学期内的月次',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  KEY `type_id` (`type_id`),
  KEY `admin_id` (`admin_id`),
  KEY `semester_id` (`semester_id`),
  CONSTRAINT `score_records_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `score_records_ibfk_2` FOREIGN KEY (`type_id`) REFERENCES `reward_punish_types` (`id`),
  CONSTRAINT `score_records_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`),
  CONSTRAINT `score_records_ibfk_4` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 操作日志表
CREATE TABLE `admin_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 插入默认年级
INSERT INTO `grades` (`name`) VALUES ('高一'), ('高二'), ('高三');

-- 插入内置奖惩类型
INSERT INTO `reward_punish_types` (`name`, `type`, `default_points`, `is_builtin`) VALUES
('迟到', 'punish', -2.0, 1),
('旷课', 'punish', -5.0, 1),
('作业未交', 'punish', -1.0, 1),
('违纪行为', 'punish', -3.0, 1),
('好人好事', 'reward', 3.0, 1),
('课堂表现优秀', 'reward', 2.0, 1);

-- ============================================
-- 已有数据库升级脚本（如果是从旧版本升级）
-- 运行以下 SQL 添加缺失字段：
-- ============================================
-- ALTER TABLE `admins`
--   ADD COLUMN `security_question` varchar(255) DEFAULT NULL COMMENT '密保问题' AFTER `lock_until`,
--   ADD COLUMN `security_answer_hash` varchar(255) DEFAULT NULL COMMENT '密保答案哈希' AFTER `security_question`;
-- ALTER TABLE `score_records`
--   ADD COLUMN `image_path` varchar(255) DEFAULT NULL COMMENT '截图路径' AFTER `note`;
-- ALTER TABLE `reward_punish_types`
--   ADD COLUMN `category` varchar(50) DEFAULT NULL COMMENT '分类（如：卫生、纪律）' AFTER `type`;