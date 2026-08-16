<?php
// ========== 一体式安装脚本 ==========
// 流程：
// Step 1 : 输入数据库连接信息 -> 测试连接 -> 自动创建数据库 -> 写入 config.php
// Step 2 : 执行建表 SQL（内嵌 database.sql 内容，自动替换 role 枚举）
// Step 3 : 填写超级管理员账号 -> 写入 admins 表 -> 创建 install.lock
// Reset  : 忘记密码（密保验证后重置）
// =====================================

session_start();
date_default_timezone_set('Asia/Shanghai');
define('INSTALL_PHASE', true);

// 已安装保护（忘记密码流程豁免）
$action = $_GET['action'] ?? '';
$lockFile = __DIR__ . '/install.lock';
$isInstalled = file_exists($lockFile);
if ($isInstalled && $action !== 'reset') {
    header('Location: index.php');
    exit;
}

// ========== 步骤状态 ==========
$step = intval($_SESSION['install_step'] ?? 1);
$dbConfig = $_SESSION['install_db_config'] ?? [];
$installMode = $_SESSION['install_mode'] ?? 'fresh';

// 用户切换步骤保护
if (empty($dbConfig)) {
    $step = 1;
} elseif (!isset($_SESSION['install_tables_done'])) {
    if ($step >= 3) $step = 2;
}

// ========== 共用：写入 config.php 模板 ==========
function writeConfigPhp($host, $name, $user, $pass) {
    $h = addslashes($host);
    $n = addslashes($name);
    $u = addslashes($user);
    $p = addslashes($pass);
    $template = <<<'PHP'
<?php
// 本文件由 班级积分系统 一体式安装脚本自动生成，请妥善保管。
session_start();

define('INSTALL_LOCK_FILE', __DIR__ . '/install.lock');

// 未安装时先跳转到一体式安装向导喵（install.php 通过 INSTALL_PHASE 豁免，避免死循环喵）
if (!file_exists(INSTALL_LOCK_FILE) && !defined('INSTALL_PHASE')) {
    header('Location: install.php');
    exit;
}

if (!defined('DB_HOST')) define('DB_HOST', '__DBHOST__');
if (!defined('DB_NAME')) define('DB_NAME', '__DBNAME__');
if (!defined('DB_USER')) define('DB_USER', '__DBUSER__');
if (!defined('DB_PASS')) define('DB_PASS', '__DBPASS__');

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 60);
date_default_timezone_set('Asia/Shanghai');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
            PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
            PDO::MYSQL_ATTR_SSL_CA        => null,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]
    );
} catch (PDOException $e) {
    if (!defined('INSTALL_PHASE')) {
        die("数据库连接失败喵: " . $e->getMessage());
    }
}
PHP;
    $template = strtr($template, [
        '__DBHOST__' => $h,
        '__DBNAME__' => $n,
        '__DBUSER__' => $u,
        '__DBPASS__' => $p,
    ]);
    // 附加公共函数片段（从 config_public 段重建）
    $template .= PHP_EOL . getConfigFunctionsSnippet();
    $written = @file_put_contents(__DIR__ . '/config.php', $template);
    if ($written === false) {
        return false;
    }
    // 重置 OPCache
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__DIR__ . '/config.php', true);
    }
    return true;
}

// 公共函数段（保持与 config.php 主逻辑一致的精简复现）
function getConfigFunctionsSnippet() {
    return <<<'PHPTAG'

// ========== 新角色权限体系 ==========
// super_admin  超级管理员（唯一root，只能存在1个，不可删除）
// system_admin 系统管理员（负责系统运维 + 积分应急权限）
// score_admin  普通积分管理员（管全部积分业务，无系统设置权限）

function currentRole() { return $_SESSION['role'] ?? 'guest'; }
function isSuperAdmin() { return currentRole() === 'super_admin'; }
function isSystemAdmin() { return currentRole() === 'system_admin'; }
function isScoreAdmin() { return currentRole() === 'score_admin'; }
function canOperateScore() { return in_array(currentRole(), ['super_admin','system_admin','score_admin']); }
function canOperateSystem() { return in_array(currentRole(), ['super_admin','system_admin']); }
function canManageUsers() { return isSuperAdmin(); }
function isGuest() { return !isset($_SESSION['admin_id']); }

function getMySQLVersion() {
    global $pdo; if (!$pdo) return '5.7';
    static $v = null; if ($v !== null) return $v;
    try {
        $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
        $v = version_compare($ver, '8.0', '>=') ? '8.0' : (version_compare($ver, '5.7', '>=') ? '5.7' : '5.6');
    } catch (PDOException $e) { $v = '5.7'; }
    return $v;
}
function isMySQL80() { return getMySQLVersion() === '8.0'; }

function getCurrentSemester() {
    global $pdo; if (!$pdo) return false;
    $stmt = $pdo->query("SELECT * FROM semesters WHERE is_current = 1 LIMIT 1");
    return $stmt->fetch();
}
function requireLogin() { if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; } }
function checkRole($allowed) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed)) die("权限不足喵");
}
function logAction($action, $target_type = null, $target_id = null, $details = null) {
    global $pdo;
    if (!$pdo || isGuest()) return;
    $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$_SESSION['admin_id'], $action, $target_type, $target_id, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
}
function getWeekNumber($semesterStartDate) {
    $tz = new DateTimeZone('Asia/Shanghai');
    $start = new DateTime($semesterStartDate, $tz);
    $now = new DateTime('now', $tz);
    $startWeekMonday = clone $start;
    $startDayOfWeek = (int)$start->format('N');
    if ($startDayOfWeek > 1) $startWeekMonday->modify('-' . ($startDayOfWeek - 1) . ' days');
    $startWeekMonday->setTime(0, 0, 0);
    $currentWeekMonday = clone $now;
    $currentDayOfWeek = (int)$now->format('N');
    if ($currentDayOfWeek > 1) $currentWeekMonday->modify('-' . ($currentDayOfWeek - 1) . ' days');
    $currentWeekMonday->setTime(0, 0, 0);
    $dayDiff = $currentWeekMonday->diff($startWeekMonday)->days;
    return max(1, floor($dayDiff / 7) + 1);
}
function getMonthNumber($semesterStartDate) {
    $tz = new DateTimeZone('Asia/Shanghai');
    $start = new DateTime($semesterStartDate, $tz);
    $now = new DateTime('now', $tz);
    $diff = $start->diff($now);
    return max(1, $diff->y * 12 + $diff->m + 1);
}
function getMonthDateRange($semesterStartDate, $monthNumber) {
    $tz = new DateTimeZone('Asia/Shanghai');
    $start = new DateTime($semesterStartDate, $tz);
    $start->modify('+'.($monthNumber - 1).' month');
    $monthStart = clone $start;
    $monthStart->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd = clone $start;
    $monthEnd->modify('last day of this month')->setTime(23, 59, 59);
    return [$monthStart, $monthEnd];
}
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', '/uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('ALLOWED_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}
function validateCsrf($allowSkip = false) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        if ($allowSkip) return false;
        http_response_code(403); die('CSRF 验证失败喵，请刷新页面后重试喵');
    }
    return true;
}
function isSystemInitialized() {
    global $pdo; if (!$pdo) return false;
    try { $stmt = $pdo->query("SELECT COUNT(*) FROM admins"); return $stmt->fetchColumn() > 0; }
    catch (PDOException $e) { return false; }
}
function getSuperAdminCount() {
    global $pdo; if (!$pdo) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'");
        $stmt->execute(); return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}
function getSecurityQuestions() {
    return [
        '您的出生地是哪里？',
        '您母亲的姓名是什么？',
        '您父亲的姓名是什么？',
        '您的小学校名是什么？',
        '您最敬爱的老师名字是什么？',
        '您最喜欢的宠物名字是什么？',
        '您最喜欢的书籍名称是什么？',
        '您的身份证号码后六位是什么？',
    ];
}

// ========== 自动数据库迁移（旧角色 -> 新角色） ==========
if (isset($pdo) && $pdo) {
    try {
        $stmt = $pdo->query("SELECT DISTINCT role FROM admins");
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('grade_admin', $roles) || in_array('class_teacher', $roles)) {
            $pdo->exec("UPDATE admins SET role = 'system_admin' WHERE role = 'grade_admin'");
            $pdo->exec("UPDATE admins SET role = 'score_admin' WHERE role = 'class_teacher'");
        }
    } catch (PDOException $e) {}
    foreach ([
        "SHOW COLUMNS FROM admins LIKE 'security_question'" =>
            "ALTER TABLE admins ADD COLUMN `security_question` varchar(255) DEFAULT NULL AFTER `lock_until`, ADD COLUMN `security_answer_hash` varchar(255) DEFAULT NULL AFTER `security_question`",
        "SHOW COLUMNS FROM admins LIKE 'totp_secret'" =>
            "ALTER TABLE admins ADD COLUMN `totp_secret` varchar(64) DEFAULT NULL AFTER `security_answer_hash`",
        "SHOW COLUMNS FROM score_records LIKE 'image_path'" =>
            "ALTER TABLE score_records ADD COLUMN `image_path` varchar(255) DEFAULT NULL AFTER `note`",
        "SHOW COLUMNS FROM reward_punish_types LIKE 'category'" =>
            "ALTER TABLE reward_punish_types ADD COLUMN `category` varchar(50) DEFAULT NULL AFTER `type`",
    ] as $checkSql => $alterSql) {
        try {
            $stmt = $pdo->query($checkSql);
            if (!$stmt->fetch()) $pdo->exec($alterSql);
        } catch (PDOException $e) {}
    }
}

// ========== TOTP ==========
function base32_decode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = strtoupper(str_replace('=', '', $data));
    $buffer = 0; $bitsLeft = 0; $output = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $val = strpos($alphabet, $data[$i]);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $output .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
            $bitsLeft -= 8;
        }
    }
    return $output;
}
function base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = ''; $buffer = 0; $bitsLeft = 0;
    for ($i = 0; $i < strlen($data); $i++) {
        $buffer = ($buffer << 8) | ord($data[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $output .= $alphabet[($buffer >> ($bitsLeft - 5)) & 0x1F];
            $bitsLeft -= 5;
        }
    }
    if ($bitsLeft > 0) $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
    return $output;
}
function generateTotpSecret() { return base32_encode(random_bytes(16)); }
function generateTotpUri($secret, $username) {
    $issuer = '班级积分系统';
    return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($username).'?secret='.$secret.'&issuer='.rawurlencode($issuer);
}
function verifyTotp($secret, $code) {
    $keys = base32_decode($secret);
    if ($keys === '' || $keys === false) return false;
    for ($i = -1; $i <= 1; $i++) {
        $time = floor(time() / 30) + $i;
        $packed = str_pad(pack('N', $time), 8, "\x00", STR_PAD_LEFT);
        $hash = hash_hmac('sha1', $packed, $keys, true);
        $offset = ord($hash[19]) & 0xf;
        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset+1]) & 0xff) << 16) |
            ((ord($hash[$offset+2]) & 0xff) << 8) |
            (ord($hash[$offset+3]) & 0xff)
        ) % 1000000;
        if ($value == intval($code)) return true;
    }
    return false;
}
function csvEscape($value) {
    if ($value === null) return '';
    $value = (string)$value;
    $first = substr($value, 0, 1);
    if (in_array($first, ['=','+','-','@',"\t","\r"])) $value = "\t" . $value;
    if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}
?>
PHPTAG;
}

// ========== 建表 SQL（MySQL 8.0 优化版，兼容 5.7） ==========
function getInstallTablesSQL() {
    return <<<'SQLTAG'
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '年级ID',
  `name` varchar(50) NOT NULL COMMENT '年级名称',
  CONSTRAINT `chk_grades_name` CHECK (CHAR_LENGTH(`name`) > 0),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- 年级初始化数据
INSERT INTO `grades` (`id`, `name`) VALUES
(1, '一年级'),
(2, '二年级'),
(3, '三年级'),
(4, '四年级'),
(5, '五年级'),
(6, '六年级');

-- 内置奖惩类型（不可删除）
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
SQLTAG;
}

// ========== SQL 语句拆分（按分号拆分，跳过字符串内分号） ==========
function splitSqlStatements($sql) {
    $statements = [];
    $inSingle = false; $inDouble = false; $current = '';
    for ($i = 0; $i < strlen($sql); $i++) {
        $ch = $sql[$i];
        if ($ch === "'" && ($i === 0 || $sql[$i-1] !== "\\")) $inSingle = !$inSingle;
        if ($ch === '"' && ($i === 0 || $sql[$i-1] !== "\\")) $inDouble = !$inDouble;
        if ($ch === ';' && !$inSingle && !$inDouble) {
            $st = trim($current);
            if ($st !== '') $statements[] = $st;
            $current = '';
        } else {
            $current .= $ch;
        }
    }
    $remain = trim($current);
    if ($remain !== '') $statements[] = $remain;
    return $statements;
}

// ========== 升级安装：保留原数据，仅补建缺失表 + 适配 schema ==========
function getUpgradeTablesSQL() {
    $sql = getInstallTablesSQL();
    // 移除所有 DROP TABLE（保留原数据喵）
    $sql = preg_replace('/^\s*DROP TABLE IF EXISTS .*;\s*$/m', '', $sql);
    // CREATE TABLE 改为 IF NOT EXISTS（已存在的表跳过喵）
    $sql = str_replace('CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $sql);
    // 移除种子数据 INSERT（升级时保留原有初始数据，空表才补种喵）
    $sql = preg_replace('/^\s*INSERT INTO `(?:grades|reward_punish_types)`.*?;\s*$/ms', '', $sql);
    return $sql;
}

// ========== 种子数据（全新安装或空表时插入喵） ==========
function getSeedDataSQL() {
    return <<<'SQLTAG'
INSERT INTO `grades` (`id`, `name`) VALUES
(1, '一年级'),(2, '二年级'),(3, '三年级'),(4, '四年级'),(5, '五年级'),(6, '六年级');

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
SQLTAG;
}

// ========== 升级 schema 适配：补字段 + 旧角色迁移（与 config.php 迁移 v1 保持一致喵） ==========
function upgradeSchema($pdo) {
    // 补全 admins 密保 + TOTP 字段
    try { $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS `security_question` varchar(255) DEFAULT NULL COMMENT '密保问题' AFTER `lock_until`"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS `security_answer_hash` varchar(255) DEFAULT NULL COMMENT '密保答案哈希' AFTER `security_question`"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS `totp_secret` varchar(64) DEFAULT NULL COMMENT 'TOTP密钥' AFTER `security_answer_hash`"); } catch (PDOException $e) {}
    // score_records image_path
    try { $pdo->exec("ALTER TABLE score_records ADD COLUMN IF NOT EXISTS `image_path` varchar(255) DEFAULT NULL COMMENT '截图路径' AFTER `note`"); } catch (PDOException $e) {}
    // reward_punish_types category
    try { $pdo->exec("ALTER TABLE reward_punish_types ADD COLUMN IF NOT EXISTS `category` varchar(50) DEFAULT NULL COMMENT '分类' AFTER `type`"); } catch (PDOException $e) {}
    // 旧角色迁移：grade_admin -> system_admin，class_teacher -> score_admin
    try {
        $roles = $pdo->query("SELECT DISTINCT role FROM admins")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('grade_admin', $roles) || in_array('class_teacher', $roles)) {
            $pdo->exec("UPDATE admins SET role = 'system_admin' WHERE role = 'grade_admin'");
            $pdo->exec("UPDATE admins SET role = 'score_admin' WHERE role = 'class_teacher'");
        }
    } catch (PDOException $e) {}
}

// ========== POST 处理 ==========
$message = '';
$messageType = 'info'; // info | error | success

// Step 1：提交数据库配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {
    $host = trim($_POST['db_host'] ?? '');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $autocreate = isset($_POST['auto_create_db']) ? true : false;

    if ($host === '' || $name === '' || $user === '') {
        $message = '请填写完整的数据库连接信息';
        $messageType = 'error';
    } else {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        try {
            $pdoTmp = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
                PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
                PDO::MYSQL_ATTR_SSL_CA        => null,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ]);

            // 检查数据库是否存在
            $stmt = $pdoTmp->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$name]);
            $dbExists = $stmt->fetchColumn() > 0;

            if (!$dbExists) {
                if (!$autocreate) {
                    $message = "数据库 {$name} 不存在，请勾选“自动创建数据库”或手动创建后再试";
                    $messageType = 'error';
                    $step = 1;
                } else {
                    $pdoTmp->exec("CREATE DATABASE `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $message = "数据库 {$name} 已自动创建";
                }
            }

            // 无异常则继续
            if (empty($message) || $messageType !== 'error') {
                // 检测是否已有数据（升级安装判断喵）
                $installMode = 'fresh';
                try {
                    $stmt = $pdoTmp->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'admins'");
                    $stmt->execute([$name]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        $installMode = 'upgrade';
                    }
                } catch (PDOException $e) {}

                // 写入 config.php
                $written = writeConfigPhp($host, $name, $user, $pass);
                if (!$written) {
                    $message = '写入 config.php 失败，请检查目录是否可写';
                    $messageType = 'error';
                    $step = 1;
                } else {
                    $_SESSION['install_db_config'] = [
                        'host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass,
                    ];
                    $_SESSION['install_mode'] = $installMode;
                    $_SESSION['install_step'] = 2;
                    $step = 2;
                    $message = $installMode === 'upgrade'
                        ? '检测到已有数据，将执行升级安装（保留原数据）喵...'
                        : '数据库连接成功！请稍候，正在建表...';
                    $messageType = 'success';
                }
            }
        } catch (PDOException $e) {
            $message = '数据库连接失败：' . $e->getMessage();
            $messageType = 'error';
            $step = 1;
        }
    }
}

// ========== Step 2：执行建表 ==========
// 先建立 PDO 连接，再执行建表（避免依赖外部 config.php）
if ($step === 2 && $isInstalled === false) {
    $cfg = $_SESSION['install_db_config'];
    $installMode = $_SESSION['install_mode'] ?? 'fresh';
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
            PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
            PDO::MYSQL_ATTR_SSL_CA        => null,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]
    );

    // 升级安装用 IF NOT EXISTS 版建表（不 DROP 保留数据），全新安装用完整版
    $sql = $installMode === 'upgrade' ? getUpgradeTablesSQL() : getInstallTablesSQL();
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $statements = splitSqlStatements($sql);

    $error = null;
    foreach ($statements as $st) {
        $st = trim($st);
        if ($st === '' || strpos($st, '--') === 0 || strpos($st, '/*') === 0 || strpos($st, '#') === 0) continue;
        try {
            $pdo->exec($st);
        } catch (PDOException $e) {
            $error = $e->getMessage();
            break;
        }
    }

    // 升级模式：空表补种子数据 + 字段/角色适配
    if ($error === null && $installMode === 'upgrade') {
        try {
            foreach (splitSqlStatements(getSeedDataSQL()) as $st) {
                $st = trim($st);
                if ($st === '' || strpos($st, '--') === 0) continue;
                if (strpos($st, 'INSERT INTO `grades`') === 0 && (int)$pdo->query("SELECT COUNT(*) FROM grades")->fetchColumn() === 0) {
                    $pdo->exec($st);
                } elseif (strpos($st, 'INSERT INTO `reward_punish_types`') === 0 && (int)$pdo->query("SELECT COUNT(*) FROM reward_punish_types")->fetchColumn() === 0) {
                    $pdo->exec($st);
                }
            }
            upgradeSchema($pdo);
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    if ($error !== null) {
        $message = ($installMode === 'upgrade' ? '升级适配失败' : '建表失败') . '：' . $error;
        $messageType = 'error';
        $step = 2;
    } elseif ($installMode === 'upgrade' && (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn() > 0) {
        // 升级且已有超管：直接完成安装，无需重复创建账号喵
        @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR']);
        unset($_SESSION['install_step'], $_SESSION['install_db_config'], $_SESSION['install_tables_done'], $_SESSION['install_mode']);
        $message = '🎉 升级安装完成喵！原数据已保留并自动适配，3 秒后跳转到登录页...';
        $messageType = 'success';
        echo "<meta http-equiv='refresh' content='3;url=index.php'>";
    } else {
        $_SESSION['install_tables_done'] = true;
        $_SESSION['install_step'] = 3;
        $step = 3;
        $message = $installMode === 'upgrade'
            ? '升级适配完成喵！原数据已保留，请创建超级管理员账号'
            : '建表成功！现在请创建唯一的超级管理员（root）账号';
        $messageType = 'success';
    }
}

// ========== Step 3：创建超级管理员 + 锁定 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '3') {
    $cfg = $_SESSION['install_db_config'];
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
            PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
            PDO::MYSQL_ATTR_SSL_CA        => null,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]
    );

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $securityQuestion = $_POST['security_question'] ?? '';
    $securityAnswer = $_POST['security_answer'] ?? '';

    $err = null;
    if ($username === '' || $password === '') {
        $err = '请填写完整账号和密码';
    } elseif (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $err = '密码强度不足，至少8位，需包含大小写字母和数字';
    } elseif ($password !== $passwordConfirm) {
        $err = '两次输入的密码不一致';
    }

    if ($err) {
        $message = $err;
        $messageType = 'error';
        $step = 3;
    } else {
        // 确保 admins 表无 super_admin
        $count = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn();
        if ($count > 0) {
            $message = '已存在超级管理员，无法重复创建';
            $messageType = 'error';
            $step = 3;
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $answerHash = null;
            if (!empty($securityQuestion) && !empty($securityAnswer)) {
                $answerHash = password_hash($securityAnswer, PASSWORD_DEFAULT);
            }
            $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, security_question, security_answer_hash) VALUES (?,?,?,?,?)");
            $stmt->execute([$username, $hash, 'super_admin', $securityQuestion ?: null, $answerHash]);

            // 创建安装锁
            @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR']);

            // 清理 session 安装状态
            unset($_SESSION['install_step'], $_SESSION['install_db_config'], $_SESSION['install_tables_done'], $_SESSION['install_mode']);

            $message = '🎉 系统安装完成！超级管理员账号已创建，3 秒后跳转到登录页...';
            $messageType = 'success';
            echo "<meta http-equiv='refresh' content='3;url=index.php'>";
        }
    }
}

// ========== 忘记密码（Reset）流程 ==========
$resetDone = false;
$resetQuestion = null;
$resetUsername = '';
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/config.php';
    $stepName = $_POST['reset_step'] ?? '1';

    if ($stepName === '1') {
        $resetUsername = trim($_POST['reset_username'] ?? '');
        if ($resetUsername === '') {
            $message = '请输入用户名';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT security_question FROM admins WHERE username = ?");
            $stmt->execute([$resetUsername]);
            $row = $stmt->fetch();
            if (!$row || empty($row['security_question'])) {
                $message = '用户不存在或未设置密保问题，无法通过该方式重置';
                $messageType = 'error';
            } else {
                $resetQuestion = $row['security_question'];
                $_SESSION['reset_username'] = $resetUsername;
                $_SESSION['reset_question'] = $resetQuestion;
            }
        }
    } elseif ($stepName === '2') {
        $resetUsername = $_SESSION['reset_username'] ?? '';
        $resetQuestion = $_SESSION['reset_question'] ?? '';
        $answer = $_POST['reset_answer'] ?? '';
        $newPwd = $_POST['new_password'] ?? '';
        $newPwdConfirm = $_POST['new_password_confirm'] ?? '';
        if ($resetUsername === '' || $resetQuestion === '') {
            $message = '会话过期，请重新开始';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT security_answer_hash, role FROM admins WHERE username = ?");
            $stmt->execute([$resetUsername]);
            $user = $stmt->fetch();
            if (!$user || !$user['security_answer_hash']) {
                $message = '数据异常，无法找回';
                $messageType = 'error';
            } elseif (!password_verify($answer, $user['security_answer_hash'])) {
                $message = '密保答案错误';
                $messageType = 'error';
            } elseif (strlen($newPwd) < 8 || !preg_match('/[a-z]/', $newPwd) || !preg_match('/[A-Z]/', $newPwd) || !preg_match('/[0-9]/', $newPwd)) {
                $message = '新密码强度不足';
                $messageType = 'error';
            } elseif ($newPwd !== $newPwdConfirm) {
                $message = '两次输入的新密码不一致';
                $messageType = 'error';
            } else {
                $hash = password_hash($newPwd, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE admins SET password_hash = ?, failed_attempts = 0, lock_until = NULL WHERE username = ?")
                    ->execute([$hash, $resetUsername]);
                unset($_SESSION['reset_username'], $_SESSION['reset_question']);
                $message = '密码重置成功！3秒后跳转到登录页';
                $messageType = 'success';
                $resetDone = true;
                echo "<meta http-equiv='refresh' content='3;url=index.php'>";
            }
        }
    }
}

// 密保问题选项（安装向导内置喵，与 config.php 保持一致喵）
$securityQuestions = [
    '您的出生地是哪里？',
    '您母亲的姓名是什么？',
    '您父亲的姓名是什么？',
    '您的小学校名是什么？',
    '您最敬爱的老师名字是什么？',
    '您最喜欢的宠物名字是什么？',
    '您最喜欢的书籍名称是什么？',
    '您的身份证号码后六位是什么？',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>班级积分系统 - 一体式安装向导</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="style.css">
<style>
    body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; margin: 0; }
    .install-wrap { max-width: 720px; margin: 3rem auto 2rem; padding: 0 1rem; }
    .install-card { background: #fff; border-radius: 1.2rem; padding: 2rem; box-shadow: 0 30px 80px rgba(0,0,0,0.25); }
    h1 { margin: 0 0 0.3rem; color: #1e3c72; text-align: center; }
    .sub { color: #64748b; text-align: center; margin-bottom: 1.5rem; }
    .steps { display: flex; justify-content: space-between; margin: 1rem 0 2rem; }
    .step-dot { flex: 1; text-align: center; position: relative; }
    .step-dot .dot { width: 36px; height: 36px; border-radius: 50%; background: #e2e8f0; color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.95rem; }
    .step-dot.active .dot { background: #1e3c72; color: #fff; }
    .step-dot.done .dot { background: #16a34a; color: #fff; }
    .step-dot .lbl { font-size: 0.75rem; color: #94a3b8; margin-top: 0.3rem; display: block; }
    .step-dot.active .lbl, .step-dot.done .lbl { color: #1e293b; font-weight: 600; }
    form .row { margin-bottom: 0.9rem; }
    label { display: block; font-size: 0.85rem; color: #334155; margin-bottom: 0.2rem; }
    input[type=text], input[type=password], input[type=number], select {
        width: 100%; padding: 0.65rem; border: 2px solid #cbd5e1; border-radius: 0.6rem;
        box-sizing: border-box; font-size: 0.95rem; outline: none; background: #f8fafc;
    }
    input:focus, select:focus { border-color: #1e3c72; background: #fff; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
    .btn {
        display: inline-block; padding: 0.75rem 1.5rem; border-radius: 0.7rem; background: #1e3c72; color: #fff;
        border: none; cursor: pointer; font-size: 1rem; font-weight: 500;
    }
    .btn:disabled { background: #cbd5e1; cursor: not-allowed; }
    .btn + .btn { margin-left: 0.5rem; }
    .btn-ghost { background: #e2e8f0; color: #1e293b; }
    .msg { padding: 0.8rem 1rem; border-radius: 0.6rem; margin-bottom: 1rem; font-size: 0.9rem; }
    .msg.info { background: #e0f2fe; color: #075985; }
    .msg.error { background: #fee2e2; color: #b91c1c; }
    .msg.success { background: #dcfce7; color: #166534; }
    .checkbox-row { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #475569; }
    .small-link { color: #1e3c72; text-decoration: none; font-size: 0.85rem; }
    .hr { height: 1px; background: #e2e8f0; margin: 1.2rem 0; }
    .hint { font-size: 0.78rem; color: #94a3b8; margin-top: 0.2rem; }
    @media (max-width: 600px) {
        .install-wrap { margin: 1rem auto 1.5rem; }
        .install-card { padding: 1.2rem; border-radius: 1rem; }
        .row2 { grid-template-columns: 1fr; }
        .steps { margin: 0.5rem 0 1.2rem; }
        .step-dot .dot { width: 30px; height: 30px; font-size: 0.85rem; }
        .step-dot .lbl { font-size: 0.68rem; }
        h1 { font-size: 1.2rem; }
        .btn { width: 100%; }
        .btn + .btn { margin-left: 0; margin-top: 0.5rem; }
    }
</style>
</head>
<body>
<div class="install-wrap">
<div class="install-card">

<!-- ========== 忘记密码视图 ========== -->
<?php if ($action === 'reset'): ?>
    <h1>🔐 密码重置（密保）</h1>
    <p class="sub">回答密保问题后即可重置密码</p>
    <?php if ($message): ?><div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($resetDone): ?>
        <p style="text-align:center; color:#16a34a;">✅ 密码重置成功，请使用新密码登录</p>
    <?php elseif ($resetQuestion): ?>
        <form method="post">
            <input type="hidden" name="reset_step" value="2">
            <div class="row">
                <label>用户名</label>
                <input type="text" value="<?= htmlspecialchars($resetUsername) ?>" readonly>
            </div>
            <div class="row">
                <label>密保问题</label>
                <input type="text" value="<?= htmlspecialchars($resetQuestion) ?>" readonly>
            </div>
            <div class="row">
                <label>您的密保答案</label>
                <input type="text" name="reset_answer" required>
            </div>
            <div class="row">
                <label>新密码</label>
                <input type="password" name="new_password" required placeholder="至少8位，大小写字母+数字">
            </div>
            <div class="row">
                <label>确认新密码</label>
                <input type="password" name="new_password_confirm" required>
            </div>
            <div style="text-align:center;">
                <button type="submit" class="btn">提交重置</button>
                <a href="index.php" class="btn btn-ghost" style="text-decoration:none;">返回</a>
            </div>
        </form>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="reset_step" value="1">
            <div class="row">
                <label>用户名</label>
                <input type="text" name="reset_username" required>
            </div>
            <div style="text-align:center;">
                <button type="submit" class="btn">下一步</button>
                <a href="index.php" class="btn btn-ghost" style="text-decoration:none;">返回登录</a>
            </div>
        </form>
    <?php endif; ?>
</div>
</div>
</body>
</html>
<?php exit; endif; ?>

<!-- ========== 安装向导 ========== -->
<h1>🎓 班级积分系统 · 一体式安装</h1>
<p class="sub">三步完成安装，无需手动导入 SQL 文件</p>

<!-- 步骤指示器 -->
<div class="steps">
    <div class="step-dot <?= $step == 1 ? 'active' : ($step > 1 ? 'done' : '') ?>"><span class="dot">1</span><span class="lbl">数据库配置</span></div>
    <div class="step-dot <?= $step == 2 ? 'active' : ($step > 2 ? 'done' : '') ?>"><span class="dot">2</span><span class="lbl"><?= $installMode === 'upgrade' ? '升级适配' : '建表初始化' ?></span></div>
    <div class="step-dot <?= $step == 3 ? 'active' : '' ?>"><span class="dot">3</span><span class="lbl">创建管理员</span></div>
</div>

<?php if ($message): ?><div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<!-- Step 1：数据库配置 -->
<?php if ($step === 1): ?>
<form method="post" autocomplete="off">
    <input type="hidden" name="step" value="1">
    <div class="row2">
        <div class="row">
            <label>数据库主机 (DB_HOST)</label>
            <input type="text" name="db_host" required value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
            <div class="hint">通常为 localhost 或 127.0.0.1</div>
        </div>
        <div class="row">
            <label>端口</label>
            <input type="number" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required>
            <div class="hint">MySQL 默认 3306</div>
        </div>
    </div>
    <div class="row">
        <label>数据库名 (DB_NAME)</label>
        <input type="text" name="db_name" required value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>">
    </div>
    <div class="row2">
        <div class="row">
            <label>数据库用户名 (DB_USER)</label>
            <input type="text" name="db_user" required value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>">
        </div>
        <div class="row">
            <label>数据库密码 (DB_PASS)</label>
            <input type="password" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
            <div class="hint">若无密码则留空</div>
        </div>
    </div>
    <div class="row">
        <label class="checkbox-row">
            <input type="checkbox" name="auto_create_db" checked>
            <span>如果数据库不存在，自动创建（需当前账号有 CREATE DATABASE 权限）</span>
        </label>
    </div>
    <div class="hr"></div>
    <div style="text-align:center;">
        <button type="submit" class="btn">测试连接并保存配置 →</button>
    </div>
</form>
<?php endif; ?>

<!-- Step 2：建表中（自动跳转，同时提供重试按钮） -->
<?php if ($step === 2): ?>
<form method="post" style="text-align:center;">
    <p><?= $installMode === 'upgrade' ? '正在执行升级适配（保留原数据），请稍候喵...' : '正在执行建表初始化，请稍候...' ?></p>
    <p class="hint">如果 5 秒后没有跳转，请点击下方按钮继续</p>
    <meta http-equiv="refresh" content="1;url=install.php">
    <button type="submit" class="btn">手动继续</button>
</form>
<?php endif; ?>

<!-- Step 3：创建唯一超级管理员 -->
<?php if ($step === 3): ?>
<form method="post" autocomplete="off">
    <input type="hidden" name="step" value="3">
    <div class="row2">
        <div class="row">
            <label>用户名</label>
            <input type="text" name="username" required placeholder="例如：admin">
            <div class="hint">将作为唯一 root 账号，创建后不可删除</div>
        </div>
        <div class="row">
            <label>角色</label>
            <input type="text" value="超级管理员（super_admin，唯一root）" readonly>
        </div>
    </div>
    <div class="row2">
        <div class="row">
            <label>登录密码</label>
            <input type="password" name="password" required placeholder="至少8位，大小写字母+数字">
        </div>
        <div class="row">
            <label>确认密码</label>
            <input type="password" name="password_confirm" required>
        </div>
    </div>
    <div class="hr"></div>
    <div class="row2">
        <div class="row">
            <label>密保问题（推荐，用于忘记密码）</label>
            <select name="security_question">
                <option value="">-- 不设置 --</option>
                <?php foreach ($securityQuestions as $q): ?>
                    <option value="<?= htmlspecialchars($q, ENT_QUOTES) ?>"><?= htmlspecialchars($q) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row">
            <label>密保答案</label>
            <input type="text" name="security_answer" placeholder="如设置了密保问题，请填写答案">
        </div>
    </div>
    <div class="hr"></div>
    <div style="text-align:center;">
        <button type="submit" class="btn">🎉 完成安装</button>
    </div>
</form>
<?php endif; ?>

<hr class="hr">
<p style="text-align:center; margin-bottom: 0;">
    <a href="index.php" class="small-link">← 返回首页</a>
    &nbsp;&nbsp;|&nbsp;&nbsp;
    <a href="install.php?action=reset" class="small-link">忘记密码（密保重置）</a>
</p>

</div>
</div>
</body>
</html>
