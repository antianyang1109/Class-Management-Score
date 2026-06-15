<?php
require_once 'config.php';
requireLogin();
if ($_SESSION['role'] !== 'super_admin') {
    die("权限不足，仅超级管理员可访问");
}

$message = '';

// 处理添加管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $grade_id = $_POST['grade_id'] ?? null;
    $class_id = $_POST['class_id'] ?? null;

    // 验证密码强度
    if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $message = "密码必须至少8位，包含大小写字母和数字";
    } elseif (!in_array($role, ['super_admin', 'grade_admin', 'class_teacher'])) {
        $message = "无效的角色";
    } else {
        // 检查用户名唯一性
        $check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $message = "用户名已存在";
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
            logAction('添加管理员', 'admin', $pdo->lastInsertId(), $username);
            $message = "管理员添加成功";
        }
    }
}

// 处理删除管理员
if (isset($_GET['delete'])) {
    $deleteId = intval($_GET['delete']);
    if ($deleteId == $_SESSION['admin_id']) {
        $message = "不能删除自己";
    } else {
        $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->execute([$deleteId]);
        logAction('删除管理员', 'admin', $deleteId);
        $message = "管理员已删除";
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
    <title>管理员账户管理</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container { max-width: 900px; margin: 1rem auto; padding: 0 1rem; }
        .message { background: #e0f2fe; color: #075985; padding: 0.8rem; border-radius: 0.8rem; margin-bottom: 1rem; }
        .message.error { background: #fee2e2; color: #b91c1c; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.7rem 0.5rem; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        tr:hover { background: #f1f5f9; }
        .btn-sm { padding: 0.2rem 0.6rem; font-size: 0.8rem; border-radius: 0.5rem; }
        .btn-delete { background: #ef4444; color: white; text-decoration: none; }
        .btn-delete:hover { background: #dc2626; }
        form .row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 0.8rem; }
        form .row > div { flex: 1; min-width: 150px; }
        label { display: block; font-size: 0.85rem; margin-bottom: 0.2rem; }
        select, input { width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; }
    </style>
</head>
<body>
<div class="admin-container">
    <h2>👥 管理员账户管理</h2>
    <p><a href="dashboard.php" class="btn-sm" style="background:#1e3c72;color:white;text-decoration:none;">← 返回仪表盘</a></p>

    <?php if ($message): ?>
        <div class="message <?= strpos($message, '成功') === false ? 'error' : '' ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>➕ 添加管理员</h3>
        <form method="post">
            <div class="row">
                <div>
                    <label>用户名</label>
                    <input type="text" name="username" required>
                </div>
                <div>
                    <label>密码</label>
                    <input type="password" name="password" required placeholder="至少8位，含大小写字母+数字">
                </div>
            </div>
            <div class="row">
                <div>
                    <label>角色</label>
                    <select name="role" id="role-select" required>
                        <option value="">-- 选择角色 --</option>
                        <option value="super_admin">超级管理员</option>
                        <option value="grade_admin">年级管理员</option>
                        <option value="class_teacher">班主任</option>
                    </select>
                </div>
                <div id="grade-field" style="display:none;">
                    <label>年级</label>
                    <select name="grade_id">
                        <option value="">-- 选择年级 --</option>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="class-field" style="display:none;">
                    <label>班级</label>
                    <select name="class_id">
                        <option value="">-- 选择班级 --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['grade_name'].' '.$c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" name="add_user" class="btn">添加管理员</button>
        </form>
    </div>

    <div class="card">
        <h3>📋 现有管理员</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>用户名</th>
                        <th>角色</th>
                        <th>关联年级/班级</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <tr>
                            <td><?= htmlspecialchars($admin['username']) ?></td>
                            <td>
                                <?php
                                $roleNames = ['super_admin' => '超级管理员', 'grade_admin' => '年级管理员', 'class_teacher' => '班主任'];
                                echo $roleNames[$admin['role']] ?? $admin['role'];
                                ?>
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
                                    <span style="color:#ef4444;">锁定中</span>
                                <?php else: ?>
                                    <span style="color:#15803d;">正常</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                    <a href="?delete=<?= $admin['id'] ?>" class="btn-sm btn-delete" onclick="return confirm('确定删除该管理员吗？')">删除</a>
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