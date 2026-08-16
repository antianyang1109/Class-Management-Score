<?php
require_once 'config.php';
requireLogin();
// 仅超级管理员可访问
if (!canManageUsers()) {
    http_response_code(403);
    die("权限不足喵，仅超级管理员可访问喵");
}

$message = '';
$superAdminCount = getSuperAdminCount();

// ========== 添加管理员（POST + CSRF） ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    validateCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $securityQuestion = $_POST['security_question'] ?? '';
    $customQuestion = trim($_POST['custom_security_question'] ?? '');
    $securityAnswer = $_POST['security_answer'] ?? '';

    // 如果选了自定义问题，使用用户输入的自定义内容
    if ($securityQuestion === '__custom__') {
        $securityQuestion = $customQuestion;
    }

    // 密码强度
    if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $message = "密码至少8位喵，包含大小写字母和数字喵";
    } elseif (!in_array($role, ['super_admin', 'system_admin', 'score_admin'])) {
        $message = "无效的角色喵";
    } elseif ($role === 'super_admin' && $superAdminCount >= 1) {
        $message = "超级管理员只能存在一个喵，无法新增喵";
    } elseif ($securityQuestion === '__custom__') {
        $message = "请输入自定义密保问题喵";
    } else {
        $check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $message = "用户名已存在喵";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $answerHash = null;
            if (!empty($securityQuestion) && !empty($securityAnswer)) {
                $answerHash = password_hash($securityAnswer, PASSWORD_DEFAULT);
            }
            $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, security_question, security_answer_hash) VALUES (?,?,?,?,?)");
            $stmt->execute([$username, $hash, $role, $securityQuestion ?: null, $answerHash]);
            $newId = $pdo->lastInsertId();
            logAction('添加管理员喵', 'admin', $newId, "角色:{$role} 用户名:{$username}");
            $message = "管理员添加成功喵";
            $superAdminCount = getSuperAdminCount();
        }
    }
}

// ========== 删除管理员（POST + CSRF + 超级管理员唯一性保护） ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    validateCsrf();
    $deleteId = intval($_POST['delete_id'] ?? 0);
    if ($deleteId <= 0) {
        $message = "参数错误喵";
    } elseif ($deleteId == $_SESSION['admin_id']) {
        $message = "不能删除自己喵";
    } else {
        $stmt = $pdo->prepare("SELECT role, username FROM admins WHERE id = ?");
        $stmt->execute([$deleteId]);
        $target = $stmt->fetch();
        if (!$target) {
            $message = "目标用户不存在喵";
        } elseif ($target['role'] === 'super_admin') {
            $message = "超级管理员不可删除喵";
        } else {
            $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$deleteId]);
            logAction('删除管理员喵', 'admin', $deleteId, "原用户名:{$target['username']}");
            $message = "管理员已删除喵";
        }
    }
}

// 获取所有管理员
$admins = $pdo->query("SELECT * FROM admins ORDER BY FIELD(role, 'super_admin','system_admin','score_admin'), username")->fetchAll();
$securityQuestions = getSecurityQuestions();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>管理员账户管理</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container { max-width: 950px; margin: 1rem auto; padding: 0 1rem; }
        .message { background: #e0f2fe; color: #075985; padding: 0.8rem; border-radius: 0.8rem; margin-bottom: 1rem; }
        .message.error { background: #fee2e2; color: #b91c1c; }
        form .row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 0.8rem; }
        form .row > div { flex: 1; min-width: 180px; }
        label { display: block; font-size: 0.85rem; margin-bottom: 0.2rem; }
        select, input { width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; box-sizing: border-box; }
        .table-wrap { overflow-x: auto; margin-top: 1rem; }
        .hint { font-size: 0.75rem; color: #64748b; margin-top: 0.2rem; }
    </style>
</head>
<body>
<div class="admin-container">
    <h2>👥 管理员账户管理喵</h2>
    <p><a href="dashboard.php" class="btn-sm" style="background:#1e3c72;color:white;text-decoration:none;">← 返回仪表盘喵</a></p>

    <?php if ($message): ?>
        <div class="message <?= strpos($message, '成功') === false ? 'error' : '' ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>➕ 添加管理员喵</h3>
        <form method="post">
            <?= csrfField() ?>
            <div class="row">
                <div>
                    <label>用户名喵</label>
                    <input type="text" name="username" required>
                </div>
                <div>
                    <label>密码喵</label>
                    <input type="password" name="password" required placeholder="至少8位，大小写字母+数字">
                </div>
                <div>
                    <label>角色喵</label>
                    <select name="role" id="role-select" required>
                        <option value="">-- 选择角色 --</option>
                        <option value="score_admin">普通积分管理员（管理全年级积分业务）</option>
                        <option value="system_admin">系统管理员（运维 + 积分应急权限）</option>
                        <?php if ($superAdminCount === 0): ?>
                            <option value="super_admin">超级管理员（唯一root）</option>
                        <?php endif; ?>
                    </select>
                    <?php if ($superAdminCount > 0): ?>
                        <div class="hint">超级管理员已存在，无法新增喵</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row">
                <div>
                    <label>密保问题（可选，用于忘记密码）</label>
                    <select name="security_question" id="security-question-select" onchange="toggleCustomQuestion()">
                        <option value="">-- 不设置 --</option>
                        <?php foreach ($securityQuestions as $q): ?>
                            <option value="<?= htmlspecialchars($q, ENT_QUOTES) ?>"><?= htmlspecialchars($q) ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">✏️ 自定义问题...</option>
                    </select>
                    <input type="text" name="custom_security_question" id="custom-question-input"
                           placeholder="请输入自定义密保问题"
                           style="display:none; margin-top:0.4rem;">
                </div>
                <div>
                    <label>密保答案</label>
                    <input type="text" name="security_answer" placeholder="填写密保问题答案">
                </div>
            </div>
            <script>
                function toggleCustomQuestion() {
                    const sel = document.getElementById('security-question-select');
                    const input = document.getElementById('custom-question-input');
                    input.style.display = (sel.value === '__custom__') ? 'block' : 'none';
                }
            </script>
            <button type="submit" name="add_user" class="btn">添加管理员喵</button>
        </form>
    </div>

    <div class="card">
        <h3>📋 现有管理员喵</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>用户名</th>
                        <th>角色</th>
                        <th>密保</th>
                        <th>二次验证</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <?php $isSuper = ($admin['role'] === 'super_admin'); ?>
                        <tr>
                            <td><?= htmlspecialchars($admin['username']) ?><?= $isSuper ? ' 👑' : '' ?></td>
                            <td>
                                <?php
                                $roleNames = [
                                    'super_admin'  => '超级管理员（root）',
                                    'system_admin' => '系统管理员',
                                    'score_admin'  => '普通积分管理员',
                                    'grade_admin'  => '年级管理员（待迁移）',
                                    'class_teacher'=> '班主任（待迁移）',
                                ];
                                echo htmlspecialchars($roleNames[$admin['role']] ?? $admin['role']);
                                if ($isSuper) {
                                    echo "<span style='display:inline-block;background:#fef3c7;color:#92400e;font-size:0.7rem;padding:0.1rem 0.4rem;border-radius:0.5rem;margin-left:0.3rem;'>唯一 / 不可删除</span>";
                                }
                                ?>
                            </td>
                            <td><?= !empty($admin['security_question']) ? '<span style="color:#16a34a;">已设置</span>' : '<span style="color:#94a3b8;">未设置</span>' ?></td>
                            <td><?= !empty($admin['totp_secret']) ? '<span style="color:#16a34a;">已启用</span>' : '<span style="color:#94a3b8;">未启用</span>' ?></td>
                            <td>
                                <?php if ($admin['lock_until'] && strtotime($admin['lock_until']) > time()): ?>
                                    <span style="color:#ef4444;">锁定中</span>
                                <?php else: ?>
                                    <span style="color:#15803d;">正常</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isSuper): ?>
                                    <span style="color:#92400e;font-size:0.8rem;background:#fef3c7;padding:0.2rem 0.5rem;border-radius:0.5rem;">🔒 不可删除</span>
                                <?php elseif ($admin['id'] != $_SESSION['admin_id']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('确定删除该管理员喵？');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="delete_id" value="<?= $admin['id'] ?>">
                                        <button type="submit" name="delete_user" class="btn-sm btn-delete">删除</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">当前用户</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
