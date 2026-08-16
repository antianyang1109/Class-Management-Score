<?php
require_once 'config.php';

// 如果系统未初始化，跳转到安装页面
if (!isSystemInitialized()) {
    header('Location: install.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $loginError = "安全验证失败，请重试";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if ($admin) {
            if ($admin['lock_until'] && strtotime($admin['lock_until']) > time()) {
                $remaining = ceil((strtotime($admin['lock_until']) - time()) / 60);
                $loginError = "账户已锁定，请等待 {$remaining} 分钟后再试";
            } elseif (password_verify($password, $admin['password_hash'])) {
                $pdo->prepare("UPDATE admins SET failed_attempts = 0, lock_until = NULL WHERE id = ?")->execute([$admin['id']]);
                if (!empty($admin['totp_secret'])) {
                    $_SESSION['pending_2fa_admin_id'] = $admin['id'];
                    $_SESSION['pending_2fa_username'] = $admin['username'];
                    header('Location: totp_verify.php');
                    exit;
                }
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['username'] = $admin['username'];
                $_SESSION['role'] = $admin['role'];
                $_SESSION['grade_id'] = $admin['grade_id'];
                $_SESSION['class_id'] = $admin['class_id'];
                logAction('登录系统');
                header('Location: dashboard.php');
                exit;
            } else {
                $failed = $admin['failed_attempts'] + 1;
                $lockUntil = null;
                if ($failed >= MAX_LOGIN_ATTEMPTS) {
                    $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
                    $loginError = "密码错误次数过多，账户已锁定1分钟";
                } else {
                    $loginError = "密码错误，还剩 " . (MAX_LOGIN_ATTEMPTS - $failed) . " 次尝试机会";
                }
                $pdo->prepare("UPDATE admins SET failed_attempts = ?, lock_until = ? WHERE id = ?")->execute([$failed, $lockUntil, $admin['id']]);
            }
        } else {
            $loginError = "用户名或密码错误";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>班级积分系统</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css">
    <style>
        /* 登录弹窗遮罩 */
        .login-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .login-overlay.show { display: flex; }
        .login-modal {
            background: white;
            padding: 2rem;
            border-radius: 1.5rem;
            width: 90%;
            max-width: 380px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            position: relative;
        }
        .login-modal h2 { text-align: center; margin-bottom: 1rem; }
        .login-modal .close-btn {
            position: absolute;
            top: 0.8rem;
            right: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: #94a3b8;
            background: none;
            border: none;
        }
        .login-modal .close-btn:hover { color: #1e293b; }
        .login-modal input {
            width: 100%;
            padding: 0.7rem;
            margin: 0.4rem 0;
            border: 1px solid #cbd5e1;
            border-radius: 0.8rem;
            box-sizing: border-box;
        }
        .login-modal button[type="submit"] {
            width: 100%;
            padding: 0.8rem;
            background: #1e3c72;
            color: white;
            border: none;
            border-radius: 0.8rem;
            font-size: 1rem;
            margin-top: 0.5rem;
            cursor: pointer;
        }
        .login-modal button[type="submit"]:hover { opacity: 0.9; }
        .login-modal .forgot-link {
            display: block;
            text-align: center;
            margin-top: 0.8rem;
            color: #64748b;
            font-size: 0.8rem;
        }
        .login-modal .pw-hint {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.5rem;
            text-align: center;
        }
        .login-modal .error-msg {
            background: #fee2e2;
            color: #b91c1c;
            padding: 0.7rem;
            border-radius: 0.8rem;
            margin-bottom: 0.8rem;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <!-- 登录弹窗 -->
    <div class="login-overlay" id="login-overlay">
        <div class="login-modal">
            <button class="close-btn" onclick="closeLogin()">&times;</button>
            <h2>🔐 管理员登录</h2>
            <?php if ($loginError): ?>
                <div class="error-msg"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="text" name="username" placeholder="用户名" required>
                <input type="password" name="password" placeholder="密码" required>
                <button type="submit" name="login_submit" value="1">登 录</button>
            </form>
            <a href="install.php?action=reset" class="forgot-link">忘记密码？</a>
            <div class="pw-hint">密码至少8位，包含大小写字母和数字</div>
        </div>
    </div>

    <div class="app">
        <header class="top-bar">
            <h1>🎓 班级积分系统</h1>
            <div class="user-info">
                <button class="btn-small" style="background:rgba(255,255,255,0.2);color:white;border:none;cursor:pointer;padding:0.3rem 0.8rem;border-radius:1rem;font-size:0.8rem;" onclick="openLogin()">登录</button>
            </div>
        </header>
        <nav class="tabs">
            <button class="tab active" data-tab="records">积分记录</button>
            <button class="tab" data-tab="ranking">排行榜</button>
        </nav>
        <main id="tab-content">
            <!-- 动态加载内容 -->
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // CSRF Token
        window._csrfToken = '<?= generateCsrfToken() ?>';

        function openLogin() {
            document.getElementById('login-overlay').classList.add('show');
        }
        function closeLogin() {
            document.getElementById('login-overlay').classList.remove('show');
        }
        // 点击遮罩关闭
        document.getElementById('login-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeLogin();
        });
        // 如果登录表单有错误，自动弹窗
        if (document.querySelector('.login-modal .error-msg')) {
            document.addEventListener('DOMContentLoaded', function() {
                openLogin();
            });
        }
    </script>
</body>
</html>
