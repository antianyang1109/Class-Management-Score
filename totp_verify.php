<?php
require_once 'config.php';

// 检查是否有待验证的登录
if (empty($_SESSION['pending_2fa_admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (empty($code)) {
        $error = '请输入验证码喵';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = '验证码为6位数字喵';
    } else {
        // 获取用户的 TOTP secret
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['pending_2fa_admin_id']]);
        $admin = $stmt->fetch();
        
        if (!$admin || empty($admin['totp_secret'])) {
            $error = '二次验证配置异常，请重新登录喵';
        } elseif (verifyTotp($admin['totp_secret'], $code)) {
            // 验证成功，完成登录
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['username'] = $admin['username'];
            $_SESSION['role'] = $admin['role'];
            $_SESSION['grade_id'] = $admin['grade_id'];
            $_SESSION['class_id'] = $admin['class_id'];
            unset($_SESSION['pending_2fa_admin_id'], $_SESSION['pending_2fa_username']);
            logAction('登录系统(二次验证)');
            header('Location: dashboard.php');
            exit;
        } else {
            $error = '验证码错误，请重试喵';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>二次验证</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css">
    <style>
        .verify-body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: linear-gradient(135deg, #1e3c72, #2a5298); }
        .verify-card { background: white; padding: 2rem; border-radius: 1.5rem; width: 90%; max-width: 380px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); text-align: center; }
        .verify-card h2 { margin-bottom: 0.5rem; }
        .verify-card .subtitle { color: #64748b; font-size: 0.85rem; margin-bottom: 1.5rem; }
        .verify-card input { width: 100%; padding: 0.8rem; border: 2px solid #cbd5e1; border-radius: 0.8rem; font-size: 1.5rem; text-align: center; letter-spacing: 0.5rem; outline: none; box-sizing: border-box; }
        .verify-card input:focus { border-color: #1e3c72; }
        .verify-card button { width: 100%; padding: 0.8rem; background: #1e3c72; color: white; border: none; border-radius: 0.8rem; font-size: 1rem; margin-top: 1rem; cursor: pointer; }
        .verify-card button:hover { opacity: 0.9; }
        .verify-card .back-link { display: block; margin-top: 1rem; color: #64748b; font-size: 0.85rem; }
        .verify-card .user-info { background: #f0f4f8; padding: 0.5rem; border-radius: 0.8rem; margin-bottom: 1rem; font-size: 0.9rem; color: #475569; }
        .msg-error { background: #fee2e2; color: #b91c1c; padding: 0.7rem; border-radius: 0.8rem; margin-bottom: 1rem; font-size: 0.9rem; }
    </style>
</head>
<body class="verify-body">
    <div class="verify-card">
        <h2>二次验证</h2>
        <p class="subtitle">请输入身份验证器中的6位动态码</p>
        
        <div class="user-info"><?= htmlspecialchars($_SESSION['pending_2fa_username'] ?? '') ?></div>
        
        <?php if ($error): ?>
            <div class="msg-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="post">
            <input type="text" name="code" placeholder="000000" maxlength="6" inputmode="numeric" pattern="\d{6}" autocomplete="one-time-code" required autofocus>
            <button type="submit">验证</button>
        </form>
        
        <a href="logout.php" class="back-link">← 返回登录</a>
    </div>
</body>
</html>
