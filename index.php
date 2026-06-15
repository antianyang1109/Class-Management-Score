<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>班级积分系统 - 登录</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <div class="login-card">
        <h2>🎓 班级积分管理</h2>
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            // 检查账户锁定
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            
            if ($admin) {
                if ($admin['lock_until'] && strtotime($admin['lock_until']) > time()) {
                    $remaining = ceil((strtotime($admin['lock_until']) - time()) / 60);
                    $error = "账户已锁定，请等待 {$remaining} 分钟后再试";
                } else {
                    if (password_verify($password, $admin['password_hash'])) {
                        // 重置失败次数
                        $pdo->prepare("UPDATE admins SET failed_attempts = 0, lock_until = NULL WHERE id = ?")->execute([$admin['id']]);
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
                            $error = "密码错误次数过多，账户已锁定1分钟";
                        } else {
                            $error = "密码错误，还剩 " . (MAX_LOGIN_ATTEMPTS - $failed) . " 次尝试机会";
                        }
                        $pdo->prepare("UPDATE admins SET failed_attempts = ?, lock_until = ? WHERE id = ?")->execute([$failed, $lockUntil, $admin['id']]);
                    }
                }
            } else {
                $error = "用户不存在";
            }
        }
        ?>
        <?php if (isset($error)) echo "<div class='error-msg'>$error</div>"; ?>
        <form method="post">
            <input type="text" name="username" placeholder="用户名" required>
            <input type="password" name="password" placeholder="密码" required>
            <button type="submit">登 录</button>
        </form>
<div style="text-align:center; margin-top:1rem;">
    <a href="dashboard.php" style="color:#1e3c72; text-decoration:underline;">👀 游客查看（无需登录）</a>
</div>
        <div class="pw-hint">密码至少8位，包含大小写字母和数字</div>
    </div>
</body>
</html>