<?php
session_start();
define('DB_HOST', 'localhost');
define('DB_NAME', 'ahszkoufen');
define('DB_USER', 'ahszkoufen');
define('DB_PASS', 'Ahszkoufen1145@');
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
    die("数据库连接失败: " . $e->getMessage());
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
        die("权限不足");
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
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 2MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
?>