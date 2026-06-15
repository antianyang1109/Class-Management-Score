<?php
require_once 'config.php';
// 不需要强制登录
$isLoggedIn = !isGuest();
$role = $_SESSION['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>班级积分仪表盘</title>
    <link rel="stylesheet" href="style.css">
    <script>
        // 全局快捷积分函数（仅管理员登录后可用）
        async function submitQuickScore() {
            const form = document.getElementById('quick-form');
            if (!form) { alert('表单未找到'); return; }
            const data = new FormData(form);
            data.append('action', 'add_score');
            try {
                const res = await fetch('api.php', { method: 'POST', body: data });
                const result = await res.text();
                alert(result);
                if (result.includes('成功')) location.reload();
            } catch (err) { alert('网络错误'); }
        }
        // 撤回积分记录
        async function deleteRecord(id) {
            if (!confirm('确定撤回该积分记录吗？')) return;
            const data = new FormData();
            data.append('action', 'delete_record');
            data.append('record_id', id);
            try {
                const res = await fetch('api.php', { method: 'POST', body: data });
                alert(await res.text());
                // 重新加载记录列表
                if (typeof loadRecords === 'function') loadRecords();
            } catch (err) { alert('网络错误'); }
        }
        async function deleteClass(id) {
          if (!confirm('确定删除该班级吗？相关积分记录将一并删除！')) return;
          const res = await fetch(`api.php?action=delete_class&id=${id}`);
          alert(await res.text());
          if (typeof loadClasses === 'function') loadClasses();
        }
        async function importClasses() {
            const form = document.getElementById('import-form');
            if (!form) {
                alert('导入表单未找到，请确保在管理页面操作');
                return;
            }
            const data = new FormData(form);
            data.append('action', 'import_classes');
            try {
                const res = await fetch('api.php', { method: 'POST', body: data });
                const result = await res.text();
                document.getElementById('import-result').innerHTML = result;
                if (typeof loadClasses === 'function') loadClasses(); // 刷新班级列表
            } catch (err) {
                alert('导入失败：' + err.message);
            }
        }   
            // 删除奖惩类型
            async function deleteType(id) {
                if (!confirm('确定删除该类型吗？若已用于积分记录则无法删除。')) return;
                const res = await fetch(`api.php?action=delete_type&id=${id}`);
                const msg = await res.text();
                alert(msg);
                if (typeof loadTypes === 'function') loadTypes();
            }
            async function deleteClass(id) {
                if (!confirm('确定删除该班级吗？相关积分记录将一并删除！')) return;
                const res = await fetch(`api.php?action=delete_class&id=${id}`);
                alert(await res.text());
                if (typeof loadClasses === 'function') loadClasses();
            }
            // 大类切换：更新小类下拉框，并设置默认分值为第一个类型的默认分
            function switchTypeCategory() {
                const cat = document.getElementById('type-category').value;
                const typeSelect = document.getElementById('type-select');
                const groups = window._groupedTypes[cat] || {};

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

            // 小类变更时更新分值（与原函数一致，支持手动调整）
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
            <h1>📋 班级积分</h1>
            <div class="user-info">
                <?php if ($isLoggedIn): ?>
                    <span><?= htmlspecialchars($_SESSION['username']) ?> (<?= $role ?>)</span>
                    <a href="logout.php" class="btn-small">退出</a>
                <?php else: ?>
                    <a href="index.php" class="btn-small">登录</a>
                <?php endif; ?>
            </div>
        </header>
        <nav class="tabs">
            <?php if ($isLoggedIn && in_array($role, ['super_admin', 'grade_admin'])): ?>
                <button class="tab active" data-tab="quick">快捷操作</button>
            <?php endif; ?>
            <button class="tab" data-tab="records">积分记录</button>
            <button class="tab" data-tab="ranking">排行榜</button>
            <?php if ($isLoggedIn && $role === 'super_admin'): ?>
                <button class="tab" data-tab="admin">管理</button>
            <?php endif; ?>
        </nav>
        <main id="tab-content">
            <!-- 动态加载内容 -->
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>