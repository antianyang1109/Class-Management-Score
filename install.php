<?php
require_once 'config.php';

$action = $_GET['action'] ?? '';

// ========== 处理忘记密码（密保重置） ==========
$resetMessage = '';
$resetError = '';
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $resetError = '安全验证失败，请刷新页面后重试';
    } else {
        $resetUsername = trim($_POST['username'] ?? '');
        $resetAnswer = trim($_POST['answer'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($resetUsername) || empty($resetAnswer) || empty($newPassword) || empty($confirmPassword)) {
            $resetError = '请填写所有字段';
        } elseif ($newPassword !== $confirmPassword) {
            $resetError = '两次密码输入不一致';
        } elseif (strlen($newPassword) < 8 || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $resetError = '密码至少8位，包含大小写字母和数字';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, security_question, security_answer_hash FROM admins WHERE username = ? AND security_question IS NOT NULL");
                $stmt->execute([$resetUsername]);
                $admin = $stmt->fetch();

                if (!$admin) {
                    $resetError = '用户不存在或未设置密保问题';
                } elseif (!password_verify($resetAnswer, $admin['security_answer_hash'])) {
                    $resetError = '密保答案错误';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE admins SET password_hash = ?, failed_attempts = 0, lock_until = NULL WHERE id = ?")
                        ->execute([$hash, $admin['id']]);
                    $resetMessage = '密码重置成功，请前往登录页面登录';
                }
            } catch (PDOException $e) {
                $resetError = '系统错误，请重试';
            }
        }
    }
}

// ========== 处理初始安装 ==========
$installMessage = '';
$installError = '';
$postAction = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'setup') {
    // CSRF 验证
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $installError = '安全验证失败，请刷新页面后重试';
    } elseif (isSystemInitialized()) {
        $installError = '系统已初始化，不可重复安装';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $securityQuestion = trim($_POST['security_question'] ?? '');
        $securityAnswer = trim($_POST['security_answer'] ?? '');

        $questions = getSecurityQuestions();

        if (empty($username) || empty($password) || empty($confirmPassword)) {
            $installError = '请填写用户名和密码';
        } elseif ($password !== $confirmPassword) {
            $installError = '两次密码输入不一致';
        } elseif (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $installError = '密码至少8位，包含大小写字母和数字';
        } elseif (!empty($securityQuestion) && !in_array($securityQuestion, $questions)) {
            $installError = '请选择有效的密保问题';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $answerHash = !empty($securityAnswer) ? password_hash($securityAnswer, PASSWORD_DEFAULT) : null;

                $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, security_question, security_answer_hash) VALUES (?, ?, 'super_admin', ?, ?)");
                $stmt->execute([$username, $passwordHash, $securityQuestion ?: null, $answerHash]);

                $installMessage = '🎉 系统初始化成功！超级管理员账号已创建，请前往登录';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $installError = '用户名已存在';
                } else {
                    $installError = '安装失败：' . $e->getMessage();
                }
            }
        }
    }
}

// ========== 根据状态显示页面 ==========
$isInitialized = isSystemInitialized();

// 如果是安装成功或重置成功，显示简单页面
if ($installMessage || $resetMessage) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>系统安装</title>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
        <link rel="stylesheet" href="style.css">
        <style>
            .success-card { text-align: center; padding: 2rem; }
            .success-card .icon { font-size: 3rem; margin-bottom: 1rem; }
            .success-card p { color: #475569; margin: 1rem 0; line-height: 1.6; }
            .success-card .btn { display: inline-block; margin-top: 0.5rem; text-decoration: none; }
        </style>
    </head>
    <body class="login-body">
        <div class="login-card success-card">
            <?php if ($installMessage): ?>
                <div class="icon">✅</div>
                <h2>安装成功</h2>
                <p><?= htmlspecialchars($installMessage) ?></p>
                <a href="index.php" class="btn">前往登录</a>
            <?php else: ?>
                <div class="icon">🔑</div>
                <h2>密码重置成功</h2>
                <p><?= htmlspecialchars($resetMessage) ?></p>
                <a href="index.php" class="btn">前往登录</a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 重置密码时显示密保验证页面
if ($action === 'reset') {
    $queryUsername = $_GET['username'] ?? '';
    $userQuestion = '';
    if ($queryUsername) {
        $stmt = $pdo->prepare("SELECT security_question FROM admins WHERE username = ? AND security_question IS NOT NULL");
        $stmt->execute([$queryUsername]);
        $userRow = $stmt->fetch();
        if ($userRow) {
            $userQuestion = $userRow['security_question'];
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>忘记密码 - 密保重置</title>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
        <link rel="stylesheet" href="style.css">
        <style>
            .reset-card { max-width: 440px; }
            .reset-card h2 { text-align: center; margin-bottom: 1rem; }
            .reset-card label { display: block; font-size: 0.85rem; color: #475569; margin-top: 0.8rem; margin-bottom: 0.2rem; }
            .reset-card input, .reset-card select { width: 100%; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 0.8rem; font-size: 0.95rem; }
            .reset-card button { width: 100%; padding: 0.8rem; background: #1e3c72; color: white; border: none; border-radius: 0.8rem; font-size: 1rem; margin-top: 1rem; cursor: pointer; }
            .reset-card button:hover { opacity: 0.9; }
            .reset-card .question-display { background: #f0f4f8; padding: 0.8rem; border-radius: 0.8rem; margin: 0.5rem 0; font-weight: 500; color: #1e3c72; }
            .reset-card .back-link { display: block; text-align: center; margin-top: 1rem; color: #64748b; font-size: 0.85rem; }
            .msg-success { background: #dcfce7; color: #15803d; padding: 0.7rem; border-radius: 0.8rem; margin-bottom: 1rem; }
            .msg-error { background: #fee2e2; color: #b91c1c; padding: 0.7rem; border-radius: 0.8rem; margin-bottom: 1rem; }
        </style>
        <script>
            function loadSecurityQuestion() {
                const username = document.getElementById('reset-username').value.trim();
                if (!username) return;
                fetch('api.php?action=get_security_question&username=' + encodeURIComponent(username))
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        const display = document.getElementById('question-display');
                        const answerGroup = document.getElementById('answer-group');
                        if (data.question) {
                            display.textContent = '🔒 ' + data.question;
                            display.style.display = 'block';
                            answerGroup.style.display = 'block';
                        } else {
                            display.textContent = data.error || '该用户不存在或未设置密保问题';
                            display.style.display = 'block';
                            answerGroup.style.display = 'none';
                        }
                    })
                    .catch(function() {
                        document.getElementById('question-display').textContent = '查询失败，请重试';
                        document.getElementById('question-display').style.display = 'block';
                    });
            }
        </script>
    </head>
    <body class="login-body">
        <div class="login-card reset-card">
            <h2>🔑 忘记密码</h2>
            <p style="text-align:center;color:#64748b;font-size:0.85rem;margin-bottom:1rem;">回答密保问题以重置密码</p>

            <?php if ($resetError): ?>
                <div class="msg-error"><?= htmlspecialchars($resetError) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <label>用户名</label>
                <input type="text" id="reset-username" name="username" value="<?= htmlspecialchars($queryUsername) ?>" required onchange="loadSecurityQuestion()" placeholder="请输入用户名">

                <div id="question-display" class="question-display" style="display:<?= $queryUsername && $userQuestion ? 'block' : 'none' ?>;">
                    <?= $queryUsername && $userQuestion ? '🔒 ' . htmlspecialchars($userQuestion) : '' ?>
                </div>

                <div id="answer-group" style="display:<?= $queryUsername && $userQuestion ? 'block' : 'none' ?>;">
                    <label>密保答案</label>
                    <input type="text" name="answer" placeholder="请输入密保答案" required>

                    <label>新密码</label>
                    <input type="password" name="new_password" placeholder="至少8位，含大小写字母+数字" required>

                    <label>确认新密码</label>
                    <input type="password" name="confirm_password" placeholder="再次输入新密码" required>
                </div>

                <button type="submit">重置密码</button>
            </form>

            <a href="index.php" class="back-link">← 返回登录</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 如果已初始化但未指定 reset 操作，显示"已安装"页面
if ($action !== 'setup' && $isInitialized) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>系统已安装</title>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
        <link rel="stylesheet" href="style.css">
        <style>
            .info-card { text-align: center; padding: 2rem; }
            .info-card .icon { font-size: 3rem; margin-bottom: 1rem; }
            .info-card p { color: #475569; margin: 0.8rem 0; line-height: 1.6; }
            .info-card .btn { display: inline-block; margin: 0.5rem 0.3rem; text-decoration: none; }
            .info-card .btn-outline { display: inline-block; margin: 0.5rem 0.3rem; padding: 0.6rem 1.2rem; border-radius: 0.8rem; border: 1px solid #1e3c72; color: #1e3c72; text-decoration: none; }
            .info-card .btn-outline:hover { background: #f8fafc; }
        </style>
    </head>
    <body class="login-body">
        <div class="login-card info-card">
            <div class="icon">✅</div>
            <h2>系统已初始化</h2>
            <p>系统已成功安装并配置完毕，无需再次安装。</p>
            <div style="margin-top:1rem;">
                <a href="index.php" class="btn">前往登录</a>
                <a href="install.php?action=reset" class="btn-outline">忘记密码</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========== 首次安装页面 ==========
$questions = getSecurityQuestions();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>系统初始化安装</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css">
    <style>
        .install-body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: linear-gradient(135deg, #065f46, #047857); }
        .install-card { background: white; padding: 2rem; border-radius: 1.5rem; width: 90%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .install-card h2 { text-align: center; margin-bottom: 0.5rem; font-size: 1.3rem; }
        .install-card .subtitle { text-align: center; color: #64748b; font-size: 0.85rem; margin-bottom: 1.5rem; }
        .install-card label { display: block; font-size: 0.85rem; color: #475569; margin-top: 0.8rem; margin-bottom: 0.2rem; }
        .install-card input, .install-card select { width: 100%; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 0.8rem; font-size: 0.95rem; box-sizing: border-box; }
        .install-card button { width: 100%; padding: 0.8rem; background: #047857; color: white; border: none; border-radius: 0.8rem; font-size: 1rem; margin-top: 1.2rem; cursor: pointer; }
        .install-card button:hover { opacity: 0.9; }
        .install-card .hint { font-size: 0.75rem; color: #64748b; margin-top: 0.3rem; }
        .install-card .optional { color: #94a3b8; font-size: 0.75rem; }
        .msg-error { background: #fee2e2; color: #b91c1c; padding: 0.7rem; border-radius: 0.8rem; margin-bottom: 1rem; font-size: 0.9rem; }
        .security-section { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.8rem; padding: 1rem; margin-top: 1rem; }
        .security-section h4 { font-size: 0.9rem; color: #065f46; margin-bottom: 0.3rem; }
        .security-section .hint { color: #64748b; }
    </style>
</head>
<body class="install-body">
    <div class="install-card">
        <h2>🛠️ 系统初始化安装</h2>
        <p class="subtitle">首次使用请创建超级管理员账号</p>

        <?php if ($installError): ?>
            <div class="msg-error"><?= htmlspecialchars($installError) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <label>用户名</label>
            <input type="text" name="username" placeholder="设置超级管理员用户名" required>

            <label>密码</label>
            <input type="password" name="password" placeholder="至少8位，含大小写字母和数字" required>
            <div class="hint">密码至少8位，包含大小写字母和数字</div>

            <label>确认密码</label>
            <input type="password" name="confirm_password" placeholder="再次输入密码" required>

            <div class="security-section">
                <h4>🔒 密保设置（可选但推荐）</h4>
                <p class="hint">设置后可通过密保问题重置密码</p>

                <label>密保问题</label>
                <select name="security_question">
                    <option value="">-- 不设置密保 --</option>
                    <?php foreach ($questions as $q): ?>
                        <option value="<?= htmlspecialchars($q) ?>"><?= htmlspecialchars($q) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>密保答案</label>
                <input type="text" name="security_answer" placeholder="请牢记你的答案">
            </div>

            <button type="submit" name="action" value="setup">创建管理员并初始化系统</button>
        </form>
    </div>
</body>
</html>
