<?php
require_once 'config.php';
header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? '';
$tab = $_GET['tab'] ?? '';


/**
 *                             _ooOoo_
 *                            o8888888o
 *                            88" . "88
 *                            (| -_- |)
 *                            O\  =  /O
 *                         ____/`---'\____
 *                       .'  \\|     |//  `.
 *                      /  \\|||  :  |||//  \
 *                     /  _||||| -:- |||||-  \
 *                     |   | \\\  -  /// |   |
 *                     | \_|  ''\---/''  |   |
 *                     \  .-\__  `-`  ___/-. /
 *                   ___`. .'  /--.--\  `. . __
 *                ."" '<  `.___\_<|>_/___.'  >'"".
 *               | | :  `- \`.;`\ _ /`;.`/ - ` : | |
 *               \  \ `-.   \_ __\ /__ _/   .-` /  /
 *          ======`-.____`-.___\_____/___.-`____.-'======
 *                             `=---='
 *          ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
 *                     佛祖保佑        永无BUG
 *            佛曰:
 *                   写字楼里写字间，写字间里程序员；
 *                   程序人员写程序，又拿程序换酒钱。
 *                   酒醒只在网上坐，酒醉还来网下眠；
 *                   酒醉酒醒日复日，网上网下年复年。
 *                   但愿老死电脑间，不愿鞠躬老板前；
 *                   奔驰宝马贵者趣，公交自行程序员。
 *                   别人笑我忒疯癫，我笑自己命太贱；
 *                   不见满街漂亮妹，哪个归得程序员？
*/




/**
*注意，这是校园班级管理分网站文件
*/
// 需要当前学期的操作列表
$need_semester_actions = ['add_score', 'get_records', 'ranking', 'export_scores', 'export_records'];
$need_semester_tabs    = ['quick', 'records', 'ranking'];

$semester = null;
if (in_array($action, $need_semester_actions) || in_array($tab, $need_semester_tabs)) {
    $semester = getCurrentSemester();
    if (!$semester && !in_array($action, ['get_records', 'ranking', 'export_records', 'export_scores'])) {
        die("❌ 请先在「管理」→「学期管理」中设置当前学期。");
    }
}

// 辅助函数：返回可见班级
function getVisibleClasses() {
    global $pdo;
    $role = $_SESSION['role'] ?? 'guest';
    if ($role === 'super_admin' || $role === 'guest') {
        $stmt = $pdo->query("SELECT c.*, g.name AS grade_name FROM classes c JOIN grades g ON c.grade_id = g.id ORDER BY g.id, c.name");
        return $stmt->fetchAll();
    } elseif ($role === 'grade_admin') {
        $stmt = $pdo->prepare("SELECT c.*, g.name AS grade_name FROM classes c JOIN grades g ON c.grade_id = g.id WHERE c.grade_id = ? ORDER BY c.name");
        $stmt->execute([$_SESSION['grade_id']]);
        return $stmt->fetchAll();
    }
    return [];
}

// =================== Tab 页面输出 ===================

// 快捷操作（仅管理员）
if ($tab === 'quick' && isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])) {
    $classes = getVisibleClasses();
    $allTypes = $pdo->query("SELECT * FROM reward_punish_types ORDER BY type, category, name")->fetchAll();
    // 构造分组结构
    $groupedTypes = ['punish' => [], 'reward' => []];
    foreach ($allTypes as $t) {
        $cat = $t['category'] ?: '其他';
        $groupedTypes[$t['type']][$cat][] = $t;
    }

    function renderTypeOptions($grouped, $type) {
        $html = '';
        if (isset($grouped[$type])) {
            foreach ($grouped[$type] as $cat => $types) {
                $html .= "<optgroup label='".htmlspecialchars($cat)."'>";
                foreach ($types as $t) {
                    $icon = $t['type'] == 'punish' ? '🔻' : '🔺';
                    $html .= "<option value='{$t['id']}' data-points='{$t['default_points']}'>
                                {$icon} ".htmlspecialchars($t['name'])." ({$t['default_points']})
                              </option>";
                }
                $html .= "</optgroup>";
            }
        }
        return $html;
    }
    ?>
    <div class="card">
        <h3>⚡ 快捷积分操作</h3>
        <form id="quick-form" enctype="multipart/form-data">
            <div style="margin-bottom:0.8rem;">
                <label>班级</label>
                <select name="class_id" required>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= htmlspecialchars($c['grade_name'].' '.$c['name']) ?> <?= $c['is_frozen'] ? '(已冻结)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>奖惩大类</label>
                <select id="type-category" onchange="switchTypeCategory()">
                    <option value="punish">惩罚</option>
                    <option value="reward">奖励</option>
                </select>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>具体类型</label>
                <select name="type_id" id="type-select" onchange="updateQuickPoints(this)" required>
                    <!-- 动态填充 -->
                </select>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>分值 (可临时调整)</label>
                <input type="number" step="0.1" name="points" id="points-input" value="0" required>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>备注</label>
                <input type="text" name="note">
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>上传截图（可选，最大10MB）</label>
                <input type="file" name="image" accept="image/*">
            </div>
            <button type="button" class="btn" onclick="submitQuickScore()">提交</button>
        </form>
    </div>
    <script>
        window._groupedTypes = <?= json_encode($groupedTypes) ?>;
    </script>
    <?php
    exit;
}

// 积分记录
if ($tab === 'records') {
    $classes = getVisibleClasses();
    ?>
    <div class="card">
        <h3>📋 积分记录</h3>
        <select id="filter-class" onchange="loadRecords()">
            <option value="">全部班级</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['grade_name'].' '.$c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])): ?>
            <button class="btn" onclick="exportRecords()" style="margin-left:0.5rem;">📥 导出记录</button>
        <?php endif; ?>
        <div id="records-list"></div>
    </div>
    <script>
        async function loadRecords() {
            const classId = document.getElementById('filter-class').value;
            const res = await fetch(`api.php?action=get_records&class_id=${classId}`);
            document.getElementById('records-list').innerHTML = await res.text();
        }
        function exportRecords() {
            const classId = document.getElementById('filter-class').value;
            let url = 'api.php?action=export_records';
            if (classId) url += '&class_id=' + classId;
            window.location.href = url;
        }
        loadRecords();
    </script>
    <?php
    exit;
}

// 排行榜
if ($tab === 'ranking') {
    $period = $_GET['period'] ?? 'week';
    ?>
    <div class="card">
        <h3>🏆 排行榜</h3>
        <select id="period-select" onchange="loadRanking()">
            <option value="week" <?= $period=='week'?'selected':'' ?>>周榜</option>
            <option value="month" <?= $period=='month'?'selected':'' ?>>月榜</option>
            <option value="semester" <?= $period=='semester'?'selected':'' ?>>学期榜</option>
        </select>
        <select id="grade-filter" onchange="loadRanking()">
            <option value="">所有年级</option>
            <?php
            $grades = $pdo->query("SELECT * FROM grades")->fetchAll();
            foreach ($grades as $g) echo "<option value='{$g['id']}'>{$g['name']}</option>";
            ?>
        </select>
        <div id="ranking-content"></div>
    </div>
    <script>
        async function loadRanking() {
            const period = document.getElementById('period-select').value;
            const grade = document.getElementById('grade-filter').value;
            const res = await fetch(`api.php?action=ranking&period=${period}&grade_id=${grade}`);
            document.getElementById('ranking-content').innerHTML = await res.text();
        }
        loadRanking();
    </script>
    <?php
    exit;
}

// 管理页面（仅超级管理员）
if ($tab === 'admin' && isset($_SESSION['admin_id']) && $_SESSION['role'] === 'super_admin') {
    ?>
    <div class="grid-2">
        <div class="card">
            <h3>👥 管理员管理</h3>
            <p style="color:#64748b;">管理登录账户及角色分配</p>
            <div class="btn-row"><a href="admin_users.php" class="btn">管理账户</a></div>
        </div>
        <div class="card">
            <h3>📅 学期管理</h3>
            <p style="color:#64748b; font-size:0.85rem;">添加学期后，请点击“设为当前”激活</p>
            <form id="semester-form">
                <input type="text" name="name" placeholder="学期名称" required>
                <input type="date" name="start_date" required>
                <input type="date" name="end_date" required>
                <div class="btn-row"><button type="button" class="btn" onclick="addSemester()">添加学期</button></div>
            </form>
            <div id="semester-list" style="margin-top:1rem;"></div>
        </div>
        <div class="card">
            <h3>🏷️ 自定义奖惩类型</h3>
            <form id="type-form">
                <input type="text" name="type_name" placeholder="类型名称" required>
                <input type="text" name="category" placeholder="分类（如：卫生、纪律）">
                <select name="type_category">
                    <option value="punish">惩罚</option>
                    <option value="reward">奖励</option>
                </select>
                <input type="number" step="0.1" name="default_points" placeholder="默认分值" required>
                <div class="btn-row"><button type="button" class="btn" onclick="addType()">添加</button></div>
            </form>
            <hr>
            <div id="type-list"></div>
        </div>
        <div class="card">
            <h3>💾 数据备份与恢复</h3>
            <p style="color:#64748b; font-size:0.85rem;">导出完整备份或上传 SQL 恢复</p>
            <div class="btn-row"><a href="backup.php?action=export" class="btn">导出备份</a></div>
            <form id="restore-form" enctype="multipart/form-data" style="margin-top:0.5rem;">
                <input type="file" name="backup_file" accept=".sql">
                <div class="btn-row"><button type="button" class="btn" onclick="restoreBackup()">恢复</button></div>
            </form>
        </div>
        <div class="card">
            <h3>🏫 班级管理</h3>
            <form id="class-form">
                <select name="grade_id" required>
                    <option value="">选择年级</option>
                    <?php
                    $grades = $pdo->query("SELECT * FROM grades")->fetchAll();
                    foreach ($grades as $g) echo "<option value='{$g['id']}'>{$g['name']}</option>";
                    ?>
                </select>
                <input type="text" name="class_name" placeholder="班级名称" required>
                <input type="text" name="class_leader" placeholder="负责人姓名">
                <div class="btn-row"><button type="button" class="btn" onclick="addClass()">添加班级</button></div>
            </form>
            <hr>
            <h4>📥 批量导入</h4>
            <p style="font-size:0.8rem;"><a href="template/class_import_template.csv" download>下载 CSV 模板</a></p>
            <form id="import-form" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required>
                <div class="btn-row"><button type="button" class="btn" onclick="importClasses()">上传导入</button></div>
            </form>
            <div id="import-result" style="font-size:0.9rem; margin-top:0.5rem;"></div>
            <hr>
            <h4>📋 现有班级</h4>
            <div id="class-list"></div>
        </div>
    </div>
    <script>
        async function addSemester() {
            const form = document.getElementById('semester-form');
            const data = new FormData(form);
            data.append('action', 'add_semester');
            const res = await fetch('api.php', { method: 'POST', body: data });
            alert(await res.text());
            loadSemesters();
        }
        async function loadSemesters() {
            const res = await fetch('api.php?action=get_semesters');
            document.getElementById('semester-list').innerHTML = await res.text();
        }
        async function addType() {
            const form = document.getElementById('type-form');
            const data = new FormData(form);
            data.append('action', 'add_type');
            const res = await fetch('api.php', { method: 'POST', body: data });
            alert(await res.text());
            loadTypes();
        }
        async function loadTypes() {
            const res = await fetch('api.php?action=get_types');
            document.getElementById('type-list').innerHTML = await res.text();
        }
        async function restoreBackup() {
            const form = document.getElementById('restore-form');
            const data = new FormData(form);
            data.append('action', 'restore');
            const res = await fetch('api.php', { method: 'POST', body: data });
            alert(await res.text());
        }
        async function addClass() {
            const form = document.getElementById('class-form');
            const data = new FormData(form);
            data.append('action', 'add_class');
            const res = await fetch('api.php', { method: 'POST', body: data });
            alert(await res.text());
            loadClasses();
        }
        async function loadClasses() {
            const res = await fetch('api.php?action=get_classes');
            document.getElementById('class-list').innerHTML = await res.text();
        }
        loadSemesters();
        loadTypes();
        loadClasses();
    </script>
    <?php
    exit;
}

// =================== POST 请求处理 ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    $requiresLogin = ['add_score', 'add_semester', 'add_type', 'add_class', 'restore', 'delete_record', 'import_classes'];
    if (in_array($postAction, $requiresLogin)) {
        requireLogin();
        checkRole(['super_admin', 'grade_admin']);
    }

    // 添加积分记录
    if ($postAction === 'add_score') {
        $semester = getCurrentSemester();
        if (!$semester) die("❌ 请先设置当前学期。");

        $classId = $_POST['class_id'] ?? null;
        if (empty($classId)) die("❌ 请选择班级");

        $typeId = $_POST['type_id'] ?? null;
        if (empty($typeId)) die("❌ 请选择奖惩类型");

        $points = floatval($_POST['points'] ?? 0);
        $note = $_POST['note'] ?? '';

        $class = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $class->execute([$classId]);
        $class = $class->fetch();
        if (!$class) die("班级不存在");
        if ($_SESSION['role'] === 'grade_admin' && $class['grade_id'] != $_SESSION['grade_id']) die("权限不足");
        if ($class['is_frozen']) die("该班级已被冻结，无法操作");

        // 图片上传
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ALLOWED_TYPES)) die("不支持的文件类型，仅允许 JPG、PNG、GIF、WebP");
            if ($file['size'] > MAX_FILE_SIZE) die("文件过大，最大允许 10MB");
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = UPLOAD_DIR . $newName;
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $imagePath = UPLOAD_URL . $newName;
            } else {
                die("文件保存失败");
            }
        }

        // 计算周次和月次
        $week = getWeekNumber($semester['start_date']);
        $month = ceil((time() - strtotime($semester['start_date'])) / (30*24*3600));

        $stmt = $pdo->prepare("INSERT INTO score_records (class_id, type_id, points, admin_id, note, image_path, semester_id, week_number, month_number) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$classId, $typeId, $points, $_SESSION['admin_id'], $note, $imagePath, $semester['id'], $week, $month]);
        logAction('添加积分记录', 'class', $classId, "类型{$typeId} 分值{$points}");
        echo "操作成功";
        exit;
    }

    // 撤回记录
    if ($postAction === 'delete_record') {
        $recordId = intval($_POST['record_id'] ?? 0);
        $record = $pdo->prepare("SELECT sr.*, c.grade_id FROM score_records sr JOIN classes c ON sr.class_id = c.id WHERE sr.id = ?");
        $record->execute([$recordId]);
        $record = $record->fetch();
        if (!$record) die("记录不存在");
        if ($_SESSION['role'] === 'grade_admin' && $record['grade_id'] != $_SESSION['grade_id']) die("权限不足");

        if (!empty($record['image_path'])) {
            $filePath = __DIR__ . parse_url($record['image_path'], PHP_URL_PATH);
            if (file_exists($filePath)) @unlink($filePath);
        }

        $pdo->prepare("DELETE FROM score_records WHERE id = ?")->execute([$recordId]);
        logAction('撤回积分记录', 'record', $recordId, "原分值{$record['points']}");
        echo "撤回成功";
        exit;
    }

    // 添加学期
    if ($postAction === 'add_semester') {
        if ($_SESSION['role'] !== 'super_admin') die("权限不足");
        $name = $_POST['name'] ?? '';
        $start = $_POST['start_date'] ?? '';
        $end = $_POST['end_date'] ?? '';
        if (empty($name) || empty($start) || empty($end)) die("请填写完整信息");
        $pdo->prepare("INSERT INTO semesters (name, start_date, end_date) VALUES (?,?,?)")->execute([$name, $start, $end]);
        logAction('添加学期', 'semester', null, $name);
        echo "学期添加成功";
        exit;
    }

    // 添加奖惩类型
    if ($postAction === 'add_type') {
        $name = $_POST['type_name'] ?? '';
        $cat = $_POST['type_category'] ?? 'punish';
        $points = floatval($_POST['default_points'] ?? 0);
        $category = $_POST['category'] ?? '';
        if (empty($name)) die("请输入类型名称");
        $pdo->prepare("INSERT INTO reward_punish_types (name, type, category, default_points) VALUES (?,?,?,?)")
            ->execute([$name, $cat, $category, $points]);
        logAction('添加奖惩类型', 'type', null, $name);
        echo "类型添加成功";
        exit;
    }

    // 添加班级
    if ($postAction === 'add_class') {
        $grade_id = $_POST['grade_id'] ?? null;
        $name = trim($_POST['class_name'] ?? '');
        $leader = trim($_POST['class_leader'] ?? '');
        if (empty($grade_id) || empty($name)) die("请填写完整信息");
        if ($_SESSION['role'] === 'grade_admin' && $grade_id != $_SESSION['grade_id']) die("权限不足");
        $pdo->prepare("INSERT INTO classes (grade_id, name, class_leader) VALUES (?,?,?)")->execute([$grade_id, $name, $leader]);
        logAction('添加班级', 'class', $pdo->lastInsertId(), $name);
        echo "班级添加成功";
        exit;
    }

    // 批量导入班级
    if ($postAction === 'import_classes') {
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) die("文件上传失败");
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        fgetcsv($file);
        $success = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($file)) !== false) {
            $line++;
            if (count($row) < 2) continue;
            $gradeName = trim($row[0]);
            $className = trim($row[1]);
            $leader = trim($row[2] ?? '');
            if (empty($gradeName) || empty($className)) {
                $errors[] = "第{$line}行：年级或班级名称为空";
                continue;
            }
            $gradeStmt = $pdo->prepare("SELECT id FROM grades WHERE name = ?");
            $gradeStmt->execute([$gradeName]);
            $grade = $gradeStmt->fetch();
            if (!$grade) {
                $errors[] = "第{$line}行：年级 '{$gradeName}' 不存在";
                continue;
            }
            if ($_SESSION['role'] === 'grade_admin' && $grade['id'] != $_SESSION['grade_id']) {
                $errors[] = "第{$line}行：无权限导入其他年级";
                continue;
            }
            $check = $pdo->prepare("SELECT id FROM classes WHERE grade_id = ? AND name = ?");
            $check->execute([$grade['id'], $className]);
            if ($check->fetch()) {
                $errors[] = "第{$line}行：{$gradeName} {$className} 已存在";
                continue;
            }
            $pdo->prepare("INSERT INTO classes (grade_id, name, class_leader) VALUES (?,?,?)")->execute([$grade['id'], $className, $leader]);
            $success++;
        }
        fclose($file);
        logAction('批量导入班级', 'class', null, "成功{$success}条，错误".count($errors));
        echo "导入完成：成功 {$success} 条。" . ($errors ? "错误信息：<br>" . implode('<br>', $errors) : "");
        exit;
    }

    // 恢复备份
    if ($postAction === 'restore' && $_SESSION['role'] === 'super_admin') {
        if ($_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
            $pdo->exec($sql);
            logAction('恢复数据库备份');
            echo "恢复成功";
        } else {
            echo "文件上传失败";
        }
        exit;
    }
}

// =================== GET 数据处理 ===================

// 获取积分记录
if ($action === 'get_records') {
    $currentSemester = getCurrentSemester();
    if (!$currentSemester) { echo "<p>暂无当前学期数据</p>"; exit; }

    $classId = $_GET['class_id'] ?? '';
    $sql = "SELECT sr.id, sr.points, sr.created_at, sr.note, sr.image_path,
                   c.name AS class_name, g.name AS grade_name, t.name AS type_name, t.type AS type_category
            FROM score_records sr
            JOIN classes c ON sr.class_id = c.id
            JOIN grades g ON c.grade_id = g.id
            JOIN reward_punish_types t ON sr.type_id = t.id
            WHERE sr.semester_id = ?";
    $params = [$currentSemester['id']];
    if ($classId) {
        $sql .= " AND sr.class_id = ?";
        $params[] = $classId;
    }
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'grade_admin') {
        $sql .= " AND c.grade_id = ?";
        $params[] = $_SESSION['grade_id'];
    }
    $sql .= " ORDER BY sr.created_at DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    $isAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin']);
    echo "<table style='width:100%'><tr><th>班级</th><th>类型</th><th>分值</th><th>时间</th><th>备注</th><th>附件</th>" . ($isAdmin ? "<th>操作</th>" : "") . "</tr>";
    foreach ($records as $r) {
        echo "<tr>
                <td>{$r['grade_name']}{$r['class_name']}</td>
                <td>{$r['type_name']}</td>
                <td>{$r['points']}</td>
                <td>{$r['created_at']}</td>
                <td>".htmlspecialchars($r['note'] ?? '')."</td>
                <td>";
        if (!empty($r['image_path'])) {
            echo "<a href='{$r['image_path']}' target='_blank'><img src='{$r['image_path']}' style='max-width:60px; max-height:40px; border-radius:4px;' alt='截图'></a>";
        } else {
            echo "—";
        }
        echo "</td>";
        if ($isAdmin) {
            echo "<td><button class='btn-sm btn-delete' onclick='deleteRecord({$r['id']})'>撤回</button></td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

// 排行榜（自然周修正）
if ($action === 'ranking') {
    $currentSemester = getCurrentSemester();
    if (!$currentSemester) { echo "<p>暂无当前学期数据</p>"; exit; }

    $period = $_GET['period'] ?? 'week';
    $gradeId = $_GET['grade_id'] ?? '';

    $sql = "SELECT c.id, c.name AS class_name, g.name AS grade_name, SUM(sr.points) AS total
            FROM score_records sr
            JOIN classes c ON sr.class_id = c.id
            JOIN grades g ON c.grade_id = g.id
            WHERE sr.semester_id = ?";
    $params = [$currentSemester['id']];

    if ($period === 'week') {
        // 计算本周一的日期
        $tz = new DateTimeZone('Asia/Shanghai');
        $now = new DateTime('now', $tz);
        $weekMonday = clone $now;
        $dayOfWeek = (int)$now->format('N');
        if ($dayOfWeek > 1) {
            $weekMonday->modify('-' . ($dayOfWeek - 1) . ' days');
        }
        $weekMonday->setTime(0, 0, 0);
        $weekSunday = clone $weekMonday;
        $weekSunday->modify('+6 days')->setTime(23, 59, 59);

        $sql .= " AND sr.created_at BETWEEN ? AND ?";
        $params[] = $weekMonday->format('Y-m-d H:i:s');
        $params[] = $weekSunday->format('Y-m-d H:i:s');
    } elseif ($period === 'month') {
        // 月榜仍使用 month_number
        $month = ceil((time() - strtotime($currentSemester['start_date'])) / (30*24*3600));
        $sql .= " AND sr.month_number = ?";
        $params[] = $month;
    }

    if ($gradeId) {
        $sql .= " AND g.id = ?";
        $params[] = $gradeId;
    }
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'grade_admin') {
        $sql .= " AND g.id = ?";
        $params[] = $_SESSION['grade_id'];
    }
    $sql .= " GROUP BY c.id ORDER BY total DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ranking = $stmt->fetchAll();

    echo "<ol class='ranking-list'>";
    foreach ($ranking as $r) {
        $frozen = $pdo->query("SELECT is_frozen FROM classes WHERE id={$r['id']}")->fetchColumn() ? '❄️' : '';
        echo "<li><span>{$r['grade_name']} {$r['class_name']} $frozen</span><strong>{$r['total']} 分</strong></li>";
    }
    echo "</ol>";
    exit;
}

// 导出积分明细
if ($action === 'export_records' && isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])) {
    $currentSemester = getCurrentSemester();
    if (!$currentSemester) die("无当前学期");

    $classId = $_GET['class_id'] ?? '';
    $sql = "SELECT g.name AS grade_name, c.name AS class_name, t.name AS type_name,
                   CASE t.type WHEN 'punish' THEN '惩罚' ELSE '奖励' END AS type_category,
                   sr.points, sr.created_at, sr.note, sr.image_path
            FROM score_records sr
            JOIN classes c ON sr.class_id = c.id
            JOIN grades g ON c.grade_id = g.id
            JOIN reward_punish_types t ON sr.type_id = t.id
            WHERE sr.semester_id = ?";
    $params = [$currentSemester['id']];
    if ($classId) {
        $sql .= " AND sr.class_id = ?";
        $params[] = $classId;
    }
    if ($_SESSION['role'] === 'grade_admin') {
        $sql .= " AND c.grade_id = ?";
        $params[] = $_SESSION['grade_id'];
    }
    $sql .= " ORDER BY sr.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="score_records_'.date('YmdHis').'.csv"');
    echo "\xEF\xBB\xBF";
    echo "年级,班级,奖惩类型,类别,分值,时间,备注,图片路径\n";
    foreach ($records as $r) {
        $time = $r['created_at'];
        $note = str_replace('"', '""', $r['note'] ?? '');
        $image = $r['image_path'] ?? '';
        echo "{$r['grade_name']},{$r['class_name']},{$r['type_name']},{$r['type_category']},{$r['points']},{$time},\"{$note}\",{$image}\n";
    }
    logAction('导出积分记录明细');
    exit;
}

// 导出积分汇总（班级总分）
if ($action === 'export_scores' && isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])) {
    $currentSemester = getCurrentSemester();
    if (!$currentSemester) die("无当前学期");

    $sql = "SELECT g.name AS grade_name, c.name AS class_name, c.class_leader, c.is_frozen,
                   COALESCE(SUM(sr.points), 0) AS total
            FROM classes c
            JOIN grades g ON c.grade_id = g.id
            LEFT JOIN score_records sr ON sr.class_id = c.id AND sr.semester_id = ?
            WHERE 1=1";
    $params = [$currentSemester['id']];
    if ($_SESSION['role'] === 'grade_admin') {
        $sql .= " AND c.grade_id = ?";
        $params[] = $_SESSION['grade_id'];
    }
    $sql .= " GROUP BY c.id ORDER BY g.name, c.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="class_scores_'.date('YmdHis').'.csv"');
    echo "\xEF\xBB\xBF";
    echo "年级,班级,负责人,积分,状态\n";
    foreach ($data as $row) {
        $status = $row['is_frozen'] ? '已冻结' : '正常';
        echo "{$row['grade_name']},{$row['class_name']},".htmlspecialchars($row['class_leader']??'').",{$row['total']},{$status}\n";
    }
    logAction('导出班级积分');
    exit;
}

// 获取学期列表
if ($action === 'get_semesters') {
    $semesters = $pdo->query("SELECT * FROM semesters ORDER BY start_date DESC")->fetchAll();
    foreach ($semesters as $s) {
        $current = $s['is_current'] ? ' ✅ 当前' : '';
        echo "<div style='padding:0.5rem 0; border-bottom:1px solid #eee;'>
                {$s['name']} ({$s['start_date']} ~ {$s['end_date']}) $current
                <a href='api.php?action=set_current&id={$s['id']}' style='margin-left:1rem;'>设为当前</a>
              </div>";
    }
    exit;
}

// 设置当前学期
if ($action === 'set_current' && isset($_SESSION['admin_id']) && $_SESSION['role'] === 'super_admin') {
    $id = intval($_GET['id'] ?? 0);
    $pdo->exec("UPDATE semesters SET is_current = 0");
    $pdo->prepare("UPDATE semesters SET is_current = 1 WHERE id = ?")->execute([$id]);
    logAction('切换当前学期', 'semester', $id);
    echo "已切换当前学期，请刷新页面。";
    exit;
}

// 获取班级列表（管理）
if ($action === 'get_classes' && isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])) {
    if ($_SESSION['role'] === 'super_admin') {
        $stmt = $pdo->query("SELECT c.*, g.name AS grade_name FROM classes c JOIN grades g ON c.grade_id = g.id ORDER BY g.id, c.name");
    } else {
        $stmt = $pdo->prepare("SELECT c.*, g.name AS grade_name FROM classes c JOIN grades g ON c.grade_id = g.id WHERE c.grade_id = ? ORDER BY c.name");
        $stmt->execute([$_SESSION['grade_id']]);
    }
    $classes = $stmt->fetchAll();
    echo "<table style='width:100%'><tr><th>年级</th><th>班级</th><th>负责人</th><th>状态</th><th>操作</th></tr>";
    foreach ($classes as $c) {
        $frozen = $c['is_frozen'] ? '❄️已冻结' : '正常';
        echo "<tr>
                <td>{$c['grade_name']}</td>
                <td>{$c['name']}</td>
                <td>".htmlspecialchars($c['class_leader']??'—')."</td>
                <td>{$frozen}</td>
                <td><button class='btn-sm btn-delete' onclick='deleteClass({$c['id']})'>删除</button></td>
              </tr>";
    }
    echo "</table>";
    exit;
}

// 删除班级
if ($action === 'delete_class' && isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])) {
    $classId = intval($_GET['id'] ?? 0);
    $class = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $class->execute([$classId]);
    $class = $class->fetch();
    if (!$class) die("班级不存在");
    if ($_SESSION['role'] === 'grade_admin' && $class['grade_id'] != $_SESSION['grade_id']) die("权限不足");
    $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$classId]);
    logAction('删除班级', 'class', $classId, $class['name']);
    echo "班级已删除";
    exit;
}

// 获取奖惩类型列表
if ($action === 'get_types' && isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])) {
    $types = $pdo->query("SELECT * FROM reward_punish_types ORDER BY type, category, name")->fetchAll();
    echo "<table style='width:100%'><tr><th>分类</th><th>名称</th><th>类别</th><th>默认分值</th><th>操作</th></tr>";
    foreach ($types as $t) {
        $catLabel = $t['type'] == 'punish' ? '惩罚' : '奖励';
        $category = htmlspecialchars($t['category'] ?? '—');
        $disabled = $t['is_builtin'] ? 'disabled' : '';
        echo "<tr>
                <td>{$category}</td>
                <td>".htmlspecialchars($t['name'])."</td>
                <td>{$catLabel}</td>
                <td>{$t['default_points']}</td>
                <td><button class='btn-sm btn-delete' onclick='deleteType({$t['id']})' {$disabled}>删除</button></td>
              </tr>";
    }
    echo "</table>";
    exit;
}

// 删除奖惩类型
if ($action === 'delete_type' && isset($_SESSION['admin_id']) && in_array($_SESSION['role'], ['super_admin', 'grade_admin'])) {
    $typeId = intval($_GET['id'] ?? 0);
    $type = $pdo->prepare("SELECT * FROM reward_punish_types WHERE id = ?");
    $type->execute([$typeId]);
    $type = $type->fetch();
    if (!$type) die("类型不存在");
    if ($type['is_builtin']) die("内置类型不可删除");
    $check = $pdo->prepare("SELECT COUNT(*) FROM score_records WHERE type_id = ?");
    $check->execute([$typeId]);
    if ($check->fetchColumn() > 0) die("该类型已被用于积分记录，无法删除。");
    $pdo->prepare("DELETE FROM reward_punish_types WHERE id = ?")->execute([$typeId]);
    logAction('删除奖惩类型', 'type', $typeId, $type['name']);
    echo "删除成功";
    exit;
}