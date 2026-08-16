<?php
require_once 'config.php';
// 需要登录，游客跳转到首页
if (isGuest()) {
    header('Location: index.php');
    exit;
}
$role = $_SESSION['role'] ?? '';

// 角色名称映射（中文友好显示）
$roleNameMap = [
    'super_admin'  => '超级管理员（root）',
    'system_admin' => '系统管理员',
    'score_admin'  => '普通积分管理员',
    'grade_admin'  => '年级管理员（待迁移）',
    'class_teacher'=> '班主任（待迁移）',
];
$roleName = $roleNameMap[$role] ?? $role;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>班级积分仪表盘</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css">
    <script>
        // CSRF Token（所有 AJAX POST 请求需附带）
        window._csrfToken = '<?= generateCsrfToken() ?>';

        // ========== 全局 AJAX 快捷积分函数 ==========
        async function submitQuickScore() {
            const form = document.getElementById('quick-form');
            if (!form) { alert('表单未找到喵'); return; }
            const data = new FormData(form);
            data.append('action', 'add_score');
            data.append('csrf_token', window._csrfToken);
            try {
                const res = await fetch('api.php', { method: 'POST', body: data });
                const result = await res.text();
                alert(result);
                if (result.includes('成功')) {
                    form.reset();
                    if (typeof switchTypeCategory === 'function') switchTypeCategory();
                    if (typeof loadRecords === 'function') loadRecords();
                }
            } catch (err) { alert('网络错误喵'); }
        }

        // ========== 撤回积分记录（POST + CSRF） ==========
        async function deleteRecord(id) {
            if (!confirm('确定撤回该积分记录喵？')) return;
            const data = new FormData();
            data.append('action', 'delete_record');
            data.append('record_id', id);
            data.append('csrf_token', window._csrfToken);
            try {
                const res = await fetch('api.php', { method: 'POST', body: data });
                const msg = await res.text();
                alert(msg);
                if (typeof loadRecords === 'function') loadRecords();
            } catch (err) { alert('网络错误喵'); }
        }

        // ========== 批量导入班级（POST + CSRF） ==========
        async function importClasses() {
            const form = document.getElementById('import-form');
            if (!form) {
                alert('导入表单未找到喵，请确保在管理页面操作喵');
                return;
            }
            const data = new FormData(form);
            data.append('action', 'import_classes');
            data.append('csrf_token', window._csrfToken);
            try {
                const res = await fetch('api.php', { method: 'POST', body: data });
                const result = await res.text();
                document.getElementById('import-result').innerHTML = result;
                if (typeof loadClasses === 'function') loadClasses();
            } catch (err) {
                alert('导入失败喵：' + err.message);
            }
        }

        // ========== 大类-小类切换 + 默认分值同步 ==========
        function switchTypeCategory() {
            const cat = document.getElementById('type-category').value;
            const typeSelect = document.getElementById('type-select');
            const groups = window._groupedTypes?.[cat] || {};
            typeSelect.innerHTML = '';
            let firstOption = null;
            Object.keys(groups).forEach(function(groupName) {
                const optgroup = document.createElement('optgroup');
                optgroup.label = groupName;
                const types = groups[groupName];
                types.forEach(function(t) {
                    const option = document.createElement('option');
                    option.value = t.id;
                    option.textContent = (t.type === 'punish' ? '🔻' : '🔺') + ' ' + t.name + ' (' + t.default_points + ')';
                    option.dataset.points = t.default_points;
                    optgroup.appendChild(option);
                    if (!firstOption) firstOption = option;
                });
                typeSelect.appendChild(optgroup);
            });
            if (firstOption) {
                typeSelect.value = firstOption.value;
                updateQuickPoints(typeSelect);
            } else {
                document.getElementById('points-input').value = '0';
            }
        }
        function updateQuickPoints(selectElement) {
            const pointsInput = document.getElementById('points-input');
            if (pointsInput && selectElement.selectedIndex >= 0) {
                const selectedOption = selectElement.options[selectElement.selectedIndex];
                pointsInput.value = selectedOption.dataset.points || 0;
            }
        }
    </script>
</head>
<body>
    <div class="app">
        <header class="top-bar">
            <h1>📋 班级积分喵</h1>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['username']) ?> · <?= htmlspecialchars($roleName) ?></span>
                <a href="logout.php" class="btn-small">退出喵</a>
            </div>
        </header>
        <nav class="tabs">
            <?php if (canOperateScore()): ?>
                <button class="tab active" data-tab="quick">快捷操作喵</button>
            <?php endif; ?>
            <button class="tab" data-tab="records">积分记录</button>
            <button class="tab" data-tab="ranking">排行榜</button>
            <?php if (canOperateSystem()): ?>
                <button class="tab" data-tab="admin">管理喵</button>
            <?php endif; ?>
        </nav>
        <main id="tab-content">
            <!-- 动态加载内容 -->
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>
