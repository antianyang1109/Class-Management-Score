<?php
session_start();

// ========== 安装锁定检查（一体式安装完成后自动创建 install.lock） ==========
define('INSTALL_LOCK_FILE', __DIR__ . '/install.lock');

// 未安装时先跳转到一体式安装向导喵（install.php 通过 INSTALL_PHASE 豁免，避免死循环喵）
if (!file_exists(INSTALL_LOCK_FILE) && !defined('INSTALL_PHASE')) {
    header('Location: install.php');
    exit;
}

// ========== 数据库连接（由一体式安装向导自动写入喵，请勿手改喵） ==========
// 未安装时请先访问 install.php 完成初始化，安装成功后本文件会被自动重写喵
if (!defined('DB_HOST')) define('DB_HOST', '');
if (!defined('DB_NAME')) define('DB_NAME', '');
if (!defined('DB_USER')) define('DB_USER', '');
if (!defined('DB_PASS')) define('DB_PASS', '');

// 数据库未配置（DB_HOST 为空）说明安装尚未完成：清除可能残留的 install.lock 并引导到安装页喵
if (DB_HOST === '' && !defined('INSTALL_PHASE')) {
    if (file_exists(INSTALL_LOCK_FILE)) {
        @unlink(INSTALL_LOCK_FILE);
    }
    header('Location: install.php');
    exit;
}

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 60); // 秒
date_default_timezone_set('Asia/Shanghai');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,                     // MySQL 8.0 原生预处理，性能更好
            PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
            PDO::MYSQL_ATTR_SSL_CA        => null,                      // 显式置空喵，避免 8.0 默认 TLS 握手报错
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]
    );
} catch (PDOException $e) {
    if (!defined('INSTALL_PHASE')) {
        die("数据库连接失败喵: " . $e->getMessage());
    }
}

// ========== MySQL 版本检测 ==========
function getMySQLVersion() {
    global $pdo;
    if (!$pdo) return '5.7';
    static $version = null;
    if ($version !== null) return $version;
    try {
        $v = $pdo->query("SELECT VERSION()")->fetchColumn();
        $version = version_compare($v, '8.0', '>=') ? '8.0' : (version_compare($v, '5.7', '>=') ? '5.7' : '5.6');
    } catch (PDOException $e) {
        $version = '5.7';
    }
    return $version;
}

/** 当前 MySQL 是否为 8.0+ */
function isMySQL80() { return getMySQLVersion() === '8.0'; }

// ========== 新角色权限体系 ==========
// super_admin  超级管理员（唯一root，只能存在1个，不可删除）
// system_admin 系统管理员（原 grade_admin，负责系统运维 + 积分应急权限）
// score_admin  普通积分管理员（原 class_teacher，管全部积分业务，无系统设置权限）

/**
 * 获取当前登录用户的角色
 */
function currentRole() {
    return $_SESSION['role'] ?? 'guest';
}

/**
 * 是否为超级管理员
 */
function isSuperAdmin() {
    return currentRole() === 'super_admin';
}

/**
 * 是否为系统管理员
 */
function isSystemAdmin() {
    return currentRole() === 'system_admin';
}

/**
 * 是否为普通积分管理员
 */
function isScoreAdmin() {
    return currentRole() === 'score_admin';
}

/**
 * 是否有积分操作权限（含应急）
 * 允许：super_admin / system_admin / score_admin
 */
function canOperateScore() {
    return in_array(currentRole(), ['super_admin', 'system_admin', 'score_admin']);
}

/**
 * 是否有系统运维权限（学期/班级/奖惩类型/备份/日志/TOTP）
 * 允许：super_admin / system_admin
 */
function canOperateSystem() {
    return in_array(currentRole(), ['super_admin', 'system_admin']);
}

/**
 * 是否有管理员账号管理权限（仅超级管理员）
 */
function canManageUsers() {
    return isSuperAdmin();
}

/**
 * 是否为游客（未登录）
 */
function isGuest() {
    return !isset($_SESSION['admin_id']);
}

// 获取当前学期
function getCurrentSemester() {
    global $pdo;
    if (!$pdo) return false;
    $stmt = $pdo->query("SELECT * FROM semesters WHERE is_current = 1 LIMIT 1");
    return $stmt->fetch();
}

// 检查登录状态（仅用于需要登录的操作）
function requireLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: index.php');
        exit;
    }
}

// 检查角色权限（允许的角色数组）
function checkRole($allowed) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed)) {
        die("权限不足喵");
    }
}

// 记录日志（仅登录后）
function logAction($action, $target_type = null, $target_id = null, $details = null) {
    global $pdo;
    if (!$pdo || isGuest()) return;
    $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$_SESSION['admin_id'], $action, $target_type, $target_id, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
}

/**
 * 计算指定日期属于学期内的第几周（自然周，周一为一周起始）
 * @param string $semesterStartDate 学期开始日期
 * @param string|null $refDate 参考日期（Y-m-d 或 Y-m-d H:i:s），默认今天
 */
function getWeekNumber($semesterStartDate, $refDate = null) {
    $tz = new DateTimeZone('Asia/Shanghai');
    $start = new DateTime($semesterStartDate, $tz);
    if ($refDate) {
        $now = new DateTime($refDate, $tz);
    } else {
        $now = new DateTime('now', $tz);
    }

    // 找到学期开始日所在周的周一
    $startWeekMonday = clone $start;
    $startDayOfWeek = (int)$start->format('N'); // 1=周一
    if ($startDayOfWeek > 1) {
        $startWeekMonday->modify('-' . ($startDayOfWeek - 1) . ' days');
    }
    $startWeekMonday->setTime(0, 0, 0);

    // 找到参考日期所在周的周一
    $currentWeekMonday = clone $now;
    $currentDayOfWeek = (int)$now->format('N');
    if ($currentDayOfWeek > 1) {
        $currentWeekMonday->modify('-' . ($currentDayOfWeek - 1) . ' days');
    }
    $currentWeekMonday->setTime(0, 0, 0);

    // 计算天数差
    $dayDiff = $currentWeekMonday->diff($startWeekMonday)->days;
    $week = floor($dayDiff / 7) + 1;

    return max(1, $week);
}

/**
 * 计算指定日期属于学期内的第几月（基于日历月差，修正原30天粗略算法）
 * @param string $semesterStartDate 学期开始日期
 * @param string|null $refDate 参考日期（Y-m-d 或 Y-m-d H:i:s），默认今天
 */
function getMonthNumber($semesterStartDate, $refDate = null) {
    $tz = new DateTimeZone('Asia/Shanghai');
    $start = new DateTime($semesterStartDate, $tz);
    if ($refDate) {
        $now = new DateTime($refDate, $tz);
    } else {
        $now = new DateTime('now', $tz);
    }
    $diff = $start->diff($now);
    $month = $diff->y * 12 + $diff->m + 1; // 当月为第1月
    return max(1, $month);
}

/**
 * 获取指定学期内当前月的 month_number 对应的起止日期（用于排行榜月榜，与周榜逻辑统一为按日期范围）
 */
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
define('UPLOAD_URL', '/uploads/');   // 部署在站点根目录时无需改动喵
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ========== CSRF 防护 ==========
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}

/**
 * 验证 POST 请求中的 CSRF Token（带可选绕过开关用于 install 阶段）
 */
function validateCsrf($allowSkip = false) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        if ($allowSkip) return false;
        http_response_code(403);
        die('CSRF 验证失败喵，请刷新页面后重试喵');
    }
    return true;
}

// ========== 系统初始化检测 ==========
function isSystemInitialized() {
    global $pdo;
    if (!$pdo) return false;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 检测超级管理员数量，确保唯一性
 */
function getSuperAdminCount() {
    global $pdo;
    if (!$pdo) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
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

// ========== 版本化数据库迁移系统（替代旧 ad-hoc SHOW COLUMNS 方式） ==========
// 迁移版本号定义：大于 DB_VERSION 的条目将被执行
define('DB_VERSION', 4);

if ($pdo) {
    // 确保迁移版本表存在
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `db_migrations` (
            `id` int NOT NULL AUTO_INCREMENT,
            `version` int NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `executed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `version` (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}

    // 获取已执行的最大版本
    try {
        $applied = (int)($pdo->query("SELECT COALESCE(MAX(version), 0) FROM db_migrations")->fetchColumn());
    } catch (PDOException $e) {
        $applied = 0;
    }

    $migrations = [];

    // v1：旧角色迁移 + 新字段补全（兼容旧版升级）
    if ($applied < 1) {
        $migrations[1] = function($pdo) {
            // 旧角色迁移
            try {
                $roles = $pdo->query("SELECT DISTINCT role FROM admins")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('grade_admin', $roles) || in_array('class_teacher', $roles)) {
                    $pdo->exec("UPDATE admins SET role = 'system_admin' WHERE role = 'grade_admin'");
                    $pdo->exec("UPDATE admins SET role = 'score_admin' WHERE role = 'class_teacher'");
                }
            } catch (PDOException $e) {}
            // 补全 admins 密保 + TOTP 字段
            $pdo->exec("ALTER TABLE admins 
                ADD COLUMN IF NOT EXISTS `security_question` varchar(255) DEFAULT NULL COMMENT '密保问题' AFTER `lock_until`,
                ADD COLUMN IF NOT EXISTS `security_answer_hash` varchar(255) DEFAULT NULL COMMENT '密保答案哈希' AFTER `security_question`,
                ADD COLUMN IF NOT EXISTS `totp_secret` varchar(64) DEFAULT NULL COMMENT 'TOTP密钥' AFTER `security_answer_hash`");
            // score_records image_path
            $pdo->exec("ALTER TABLE score_records 
                ADD COLUMN IF NOT EXISTS `image_path` varchar(255) DEFAULT NULL COMMENT '截图路径' AFTER `note`");
            // reward_punish_types category
            $pdo->exec("ALTER TABLE reward_punish_types 
                ADD COLUMN IF NOT EXISTS `category` varchar(50) DEFAULT NULL COMMENT '分类' AFTER `type`");
        };
    }

    // v2：MySQL 8.0 优化（CHECK 约束 + 外键级联标准化）
    if ($applied < 2) {
        $migrations[2] = function($pdo) {
            $is80 = isMySQL80();
            // int(11) → int 已在建表时处理，存量表通过 ALTER 不改（避免锁表）
            // CHECK 约束仅 MySQL 8.0+
            if ($is80) {
                try { $pdo->exec("ALTER TABLE classes ADD CONSTRAINT IF NOT EXISTS chk_classes_name CHECK (CHAR_LENGTH(name) > 0)"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE admins ADD CONSTRAINT IF NOT EXISTS chk_admins_username CHECK (CHAR_LENGTH(username) > 0)"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE semesters ADD CONSTRAINT IF NOT EXISTS chk_semesters_date CHECK (end_date >= start_date)"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE reward_punish_types ADD CONSTRAINT IF NOT EXISTS chk_rpt_points CHECK (default_points <> 0)"); } catch (PDOException $e) {}
            }
            // 外键统一 ON DELETE 策略
            try { $pdo->exec("ALTER TABLE score_records DROP FOREIGN KEY IF EXISTS score_records_ibfk_1"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE score_records ADD CONSTRAINT score_records_ibfk_1 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE"); } catch (PDOException $e) {}
        };
    }

    // v3：复合索引优化（排行榜 / 积分记录查询加速）
    if ($applied < 3) {
        $migrations[3] = function($pdo) {
            // 积分记录按学期+班级+时间查询的复合索引
            try { $pdo->exec("ALTER TABLE score_records ADD INDEX IF NOT EXISTS idx_semester_class_time (semester_id, class_id, created_at)"); } catch (PDOException $e) {}
            // 操作日志按时间倒序的索引
            try { $pdo->exec("ALTER TABLE admin_logs ADD INDEX IF NOT EXISTS idx_created_at (created_at)"); } catch (PDOException $e) {}
            // 班级按年级+名称查询
            try { $pdo->exec("ALTER TABLE classes ADD INDEX IF NOT EXISTS idx_grade_name (grade_id, name)"); } catch (PDOException $e) {}
        };
    }

    // v4：修复 admin_logs.details 误设为 JSON 类型的问题（改回 TEXT，details 存储的是自由文本）
    if ($applied < 4) {
        $migrations[4] = function($pdo) {
            try {
                $type = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_logs' AND COLUMN_NAME = 'details'")->fetchColumn();
                if ($type && strtolower($type) === 'json') {
                    $pdo->exec("ALTER TABLE admin_logs MODIFY COLUMN `details` text DEFAULT NULL COMMENT '操作详情'");
                }
            } catch (PDOException $e) {}
        };
    }

    // 执行未应用的迁移
    foreach ($migrations as $ver => $fn) {
        try {
            $fn($pdo);
            $pdo->prepare("INSERT IGNORE INTO db_migrations (version, description) VALUES (?, ?)")->execute([$ver, "Migration v{$ver}"]);
        } catch (PDOException $e) {
            // 迁移失败不阻断系统运行，仅记录
            error_log("DB Migration v{$ver} failed: " . $e->getMessage());
        }
    }
}

// ========== TOTP 二次验证函数 ==========
function base32_decode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = strtoupper($data);
    $data = str_replace('=', '', $data);
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
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
    $output = '';
    $buffer = 0;
    $bitsLeft = 0;
    for ($i = 0; $i < strlen($data); $i++) {
        $buffer = ($buffer << 8) | ord($data[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $output .= $alphabet[($buffer >> ($bitsLeft - 5)) & 0x1F];
            $bitsLeft -= 5;
        }
    }
    if ($bitsLeft > 0) {
        $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
    }
    return $output;
}

function generateTotpSecret() {
    return base32_encode(random_bytes(16));
}

function generateTotpUri($secret, $username) {
    $issuer = '班级积分系统';
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($username)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer);
}

function verifyTotp($secret, $code) {
    $keys = base32_decode($secret);
    if ($keys === '' || $keys === false) return false;
    for ($i = -1; $i <= 1; $i++) {
        $time = floor(time() / 30) + $i;
        $packed = pack('N', $time);
        $packed = str_pad($packed, 8, "\x00", STR_PAD_LEFT);
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

// ========== CSV 导出辅助：安全转义单个字段 ==========
function csvEscape($value) {
    if ($value === null) return '';
    $value = (string)$value;
    // 防止 CSV 注入公式
    $first = substr($value, 0, 1);
    if (in_array($first, ['=', '+', '-', '@', "\t", "\r"])) {
        $value = "\t" . $value;
    }
    // 标准 CSV 双引号包裹 + 内部双引号转义
    if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}
?>
