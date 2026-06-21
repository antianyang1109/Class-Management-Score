<?php
require_once 'config.php';
requireLogin();
if ($_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    die("权限不足喵，仅超级管理员可访问喵");
}

$message = '';

// 处理添加管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    // CSRF 验证
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $message = "CSRF 验证失败喵，请刷新页面后重试喵";
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $grade_id = $_POST['grade_id'] ?? null;
    $class_id = $_POST['class_id'] ?? null;

    // 验证密码强度
    if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $message = "密码至少8位喵，包含大小写字母和数字喵";
    } elseif (!in_array($role, ['super_admin', 'grade_admin', 'class_teacher'])) {
        $message = "无效的角色喵";
    } else {
        // 检查用户名唯一性
        $check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $message = "用户名已存在喵";
        } else {
            // 根据角色设置 grade_id / class_id
            if ($role === 'grade_admin') {
                $class_id = null;
            } elseif ($role === 'class_teacher') {
                $grade_id = null;
            } elseif ($role === 'super_admin') {
                $grade_id = $class_id = null;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, grade_id, class_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $role, $grade_id ?: null, $class_id ?: null]);
            logAction('添加管理员喵', 'admin', $pdo->lastInsertId(), $username);
            $message = "管理员添加成功喵";
        }
    }
    } // end CSRF else
}

// 处理删除管理员
if (isset($_GET['delete'])) {
    $deleteId = intval($_GET['delete']);
    if ($deleteId == $_SESSION['admin_id']) {
        $message = "不能删除自己喵";
    } else {
        // 检查要删除的用户是否为最高管理员 admin
        $checkAdmin = $pdo->prepare("SELECT username FROM admins WHERE id = ?");
        $checkAdmin->execute([$deleteId]);
        $deleteUser = $checkAdmin->fetch();
        if ($deleteUser && $deleteUser['username'] === 'admin') {
            $message = "admin 为最高管理员喵，不可删除喵";
        } else {
            $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
            $stmt->execute([$deleteId]);
            logAction('删除管理员喵', 'admin', $deleteId);
            $message = "管理员已删除喵";
        }
    }
}

// 获取所有管理员
$admins = $pdo->query("SELECT a.*, g.name AS grade_name, c.name AS class_name 
                       FROM admins a 
                       LEFT JOIN grades g ON a.grade_id = g.id 
                       LEFT JOIN classes c ON a.class_id = c.id 
                       ORDER BY a.role, a.username")->fetchAll();

// 获取年级和班级列表，用于表单下拉
$grades = $pdo->query("SELECT * FROM grades")->fetchAll();
$classes = $pdo->query("SELECT c.*, g.name AS grade_name FROM classes c JOIN grades g ON c.grade_id = g.id ORDER BY g.id, c.name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>管理员账户管理喵</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container { max-width: 900px; margin: 1rem auto; padding: 0 1rem; }
        .message { background: #e0f2fe; color: #075985; padding: 0.8rem; border-radius: 0.8rem; margin-bottom: 1rem; }
        .message.error { background: #fee2e2; color: #b91c1c; }
        form .row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 0.8rem; }
        form .row > div { flex: 1; min-width: 150px; }
        label { display: block; font-size: 0.85rem; margin-bottom: 0.2rem; }
        select, input { width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; }
        .table-wrap { overflow-x: auto; margin-top: 1rem; }
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
                    <input type="password" name="password" required placeholder="至少8位喵，含大小写字母+数字喵">
                </div>
            </div>
            <div class="row">
                <div>
                    <label>角色喵</label>
                    <select name="role" id="role-select" required>
                        <option value="">-- 选择角色喵 --</option>
                        <option value="super_admin">超级管理员喵</option>
                        <option value="grade_admin">年级管理员喵</option>
                        <option value="class_teacher">班主任喵</option>
                    </select>
                </div>
                <div id="grade-field" style="display:none;">
                    <label>年级喵</label>
                    <select name="grade_id">
                        <option value="">-- 选择年级喵 --</option>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="class-field" style="display:none;">
                    <label>班级喵</label>
                    <select name="class_id">
                        <option value="">-- 选择班级喵 --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['grade_name'].' '.$c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" name="add_user" class="btn">添加管理员喵</button>
        </form>
    </div>

    <div class="card">
        <h3>📋 现有管理员喵</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>用户名喵</th>
                        <th>角色喵</th>
                        <th>关联年级/班级喵</th>
                        <th>状态喵</th>
                        <th>操作喵</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <?php $isAdminUser = ($admin['username'] === 'admin'); ?>
                        <tr>
                            <td><?= htmlspecialchars($admin['username']) ?><?= $isAdminUser ? ' 👑' : '' ?></td>
                            <td>
                                <?php
                                $roleNames = ['super_admin' => '超级管理员喵', 'grade_admin' => '年级管理员喵', 'class_teacher' => '班主任喵'];
                                echo $roleNames[$admin['role']] ?? $admin['role'];
                                ?>
                                <?php if ($isAdminUser): ?>
                                    <span style="display:inline-block;background:#fef3c7;color:#92400e;font-size:0.7rem;padding:0.1rem 0.4rem;border-radius:0.5rem;margin-left:0.3rem;">最高管理员</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ($admin['grade_name']) echo htmlspecialchars($admin['grade_name']);
                                if ($admin['class_name']) echo htmlspecialchars($admin['class_name']);
                                if (!$admin['grade_name'] && !$admin['class_name']) echo '—';
                                ?>
                            </td>
                            <td>
                                <?php if ($admin['lock_until'] && strtotime($admin['lock_until']) > time()): ?>
                                    <span style="color:#ef4444;">锁定中喵</span>
                                <?php else: ?>
                                    <span style="color:#15803d;">正常喵</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isAdminUser): ?>
                                    <span style="color:#92400e;font-size:0.8rem;background:#fef3c7;padding:0.2rem 0.5rem;border-radius:0.5rem;">🔒 不可删除喵</span>
                                <?php elseif ($admin['id'] != $_SESSION['admin_id']): ?>
                                    <a href="?delete=<?= $admin['id'] ?>" class="btn-sm btn-delete" onclick="return confirm('确定删除该管理员喵？')">删除喵</a>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">当前用户喵</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const roleSelect = document.getElementById('role-select');
    const gradeField = document.getElementById('grade-field');
    const classField = document.getElementById('class-field');

    function toggleFields() {
        const role = roleSelect.value;
        gradeField.style.display = (role === 'grade_admin') ? 'block' : 'none';
        classField.style.display = (role === 'class_teacher') ? 'block' : 'none';
    }
    roleSelect.addEventListener('change', toggleFields);
    // 初始化
    toggleFields();
</script>
</body>
</html>
