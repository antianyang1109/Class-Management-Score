<?php
session_start();
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 60); // 秒
date_default_timezone_set('Asia/Shanghai');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("数据库连接失败喵: " . $e->getMessage());
}

// 获取当前学期
function getCurrentSemester() {
    global $pdo;
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

// 判断是否为游客（未登录）
function isGuest() {
    return !isset($_SESSION['admin_id']);
}

// 记录日志（仅登录后）
function logAction($action, $target_type = null, $target_id = null, $details = null) {
    global $pdo;
    if (isGuest()) return;
    $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$_SESSION['admin_id'], $action, $target_type, $target_id, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
}

/**
 * 计算当前日期属于学期内的第几周（自然周，周一为一周起始）
 *注意，时区设置为上海时间，请根据实际情况调整
 * @param string $semesterStartDate 学期开始日期 Y-m-d
 * @return int 周次（从1开始）
 */
function getWeekNumber($semesterStartDate) {
    $tz = new DateTimeZone('Asia/Shanghai');
    $start = new DateTime($semesterStartDate, $tz);
    $now = new DateTime('now', $tz);
    
    // 找到学期开始日所在周的周一
    $startWeekMonday = clone $start;
    $startDayOfWeek = (int)$start->format('N'); // 1=周一
    if ($startDayOfWeek > 1) {
        $startWeekMonday->modify('-' . ($startDayOfWeek - 1) . ' days');
    }
    $startWeekMonday->setTime(0, 0, 0);
    
    // 找到当前日期所在周的周一
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

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', '/uploads/');   // 根据实际部署路径调整
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ========== CSRF 防护 ==========
/**
 * 生成或获取当前会话的 CSRF Token
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 输出隐藏的 CSRF input 字段（用于传统表单）
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}

/**
 * 验证 POST 请求中的 CSRF Token
 */
function validateCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('CSRF 验证失败喵，请刷新页面后重试喵');
    }
}

// ========== 系统初始化检测 ==========

/**
 * 检测系统是否已初始化（是否存在管理员账号）
 */
function isSystemInitialized() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 获取密保问题列表
 */
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

// ========== 自动数据库迁移 ==========
// 检测 admins 表是否缺少密保字段，自动添加
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM admins LIKE 'security_question'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE admins 
            ADD COLUMN `security_question` varchar(255) DEFAULT NULL COMMENT '密保问题' AFTER `lock_until`,
            ADD COLUMN `security_answer_hash` varchar(255) DEFAULT NULL COMMENT '密保答案哈希' AFTER `security_question`");
    }
} catch (PDOException $e) {
    // 表可能还不存在，忽略
}
// 检测 score_records 表是否缺少 image_path 字段，自动添加
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM score_records LIKE 'image_path'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE score_records 
            ADD COLUMN `image_path` varchar(255) DEFAULT NULL COMMENT '截图路径' AFTER `note`");
    }
} catch (PDOException $e) {
    // 表可能还不存在，忽略
}
// 检测 reward_punish_types 表是否缺少 category 字段，自动添加
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM reward_punish_types LIKE 'category'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE reward_punish_types 
            ADD COLUMN `category` varchar(50) DEFAULT NULL COMMENT '分类（如：卫生、纪律）' AFTER `type`");
    }
} catch (PDOException $e) {
    // 表可能还不存在，忽略
}
// 检测 admins 表是否缺少 totp_secret 字段
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM admins LIKE 'totp_secret'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE admins 
            ADD COLUMN `totp_secret` varchar(64) DEFAULT NULL COMMENT 'TOTP密钥' AFTER `security_answer_hash`");
    }
} catch (PDOException $e) {
    // 忽略
}

// ========== TOTP 二次验证函数 ==========

/**
 * Base32 解码
 */
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

/**
 * Base32 编码
 */
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

/**
 * 生成 TOTP Secret（16字节随机数，Base32编码）
 */
function generateTotpSecret() {
    return base32_encode(random_bytes(16));
}

/**
 * 生成 otpauth:// URI（用于二维码）
 */
function generateTotpUri($secret, $username) {
    $issuer = '班级积分系统';
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($username) 
         . '?secret=' . $secret 
         . '&issuer=' . rawurlencode($issuer);
}

/**
 * 验证 TOTP 6位验证码
 */
function verifyTotp($secret, $code) {
    $keys = base32_decode($secret);
    if ($keys === '' || $keys === false) return false;
    // 检查当前、前一个、后一个时间窗口（共3个，容忍时钟偏差）
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
?>