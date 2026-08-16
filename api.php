<?php
require_once 'config.php';
header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? '';
$tab = $_GET['tab'] ?? '';

// 需要当前学期的操作列表
$need_semester_actions = ['add_score', 'get_records', 'ranking', 'export_scores', 'export_records'];
$need_semester_tabs    = ['quick', 'records', 'ranking'];

$semester = null;
if (in_array($action, $need_semester_actions) || in_array($tab, $need_semester_tabs)) {
    $semester = getCurrentSemester();
    if (!$semester && !in_array($action, ['get_records', 'ranking', 'export_records', 'export_scores'])) {
        die("请先设置学期喵");
    }
}

// 辅助函数：返回可见班级（新角色逻辑）
function getVisibleClasses() {
    global $pdo;
    $role = currentRole();
    // super_admin / system_admin / score_admin 都可见全部班级
    if (in_array($role, ['super_admin', 'system_admin', 'score_admin']) || $role === 'guest') {
        $stmt = $pdo->query("SELECT c.*, g.name AS grade_name FROM classes c JOIN grades g ON c.grade_id = g.id ORDER BY g.id, c.name");
        return $stmt->fetchAll();
    }
    return [];
}

// =================== Tab 页面输出 ===================

// 快捷操作（积分管理员/系统管理员/超级管理员可用）
if ($tab === 'quick' && canOperateScore()) {
    $classes = getVisibleClasses();
    $allTypes = $pdo->query("SELECT * FROM reward_punish_types ORDER BY type, category, name")->fetchAll();
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
        <h3>⚡ 快捷积分操作喵</h3>
        <form id="quick-form" enctype="multipart/form-data">
            <div style="margin-bottom:0.8rem;">
                <label>班级喵</label>
                <select name="class_id" required>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= htmlspecialchars($c['grade_name'].' '.$c['name']) ?> <?= $c['is_frozen'] ? '(已冻结喵)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>奖惩大类喵</label>
                <select id="type-category" onchange="switchTypeCategory()">
                    <option value="punish">惩罚喵</option>
                    <option value="reward">奖励喵</option>
                </select>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>具体类型喵</label>
                <select name="type_id" id="type-select" onchange="updateQuickPoints(this)" required>
                </select>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>分值喵 (可临时调整喵)</label>
                <input type="number" step="0.1" name="points" id="points-input" value="0" required>
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>备注喵</label>
                <input type="text" name="note">
            </div>
            <div style="margin-bottom:0.8rem;">
                <label>上传截图喵（可选喵，最大10MB）</label>
                <input type="file" name="image" accept="image/*">
            </div>
            <button type="button" class="btn" onclick="submitQuickScore()">提交喵</button>
        </form>
    </div>
    <script>
        window._groupedTypes = <?= json_encode($groupedTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php
    exit;
}

// 积分记录（登录用户可见撤回按钮，游客只读）
if ($tab === 'records') {
    $classes = getVisibleClasses();
    $canExport = !isGuest() && canOperateScore();
    ?>
    <div class="card">
        <h3>📋 积分记录</h3>
        <div class="filter-bar">
            <select id="filter-class" onchange="loadRecords()">
                <option value="">全部班级</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['grade_name'].' '.$c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($canExport): ?>
                <button class="btn" onclick="exportRecords()">📥 导出记录</button>
            <?php endif; ?>
        </div>
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

// 排行榜（所有人可见）
if ($tab === 'ranking') {
    $period = $_GET['period'] ?? 'week';
    ?>
    <div class="card">
        <h3>🏆 排行榜</h3>
        <div class="filter-bar">
            <select id="period-select" onchange="loadRanking()">
                <option value="week" <?= $period=='week'?'selected':'' ?>>周榜</option>
                <option value="month" <?= $period=='month'?'selected':'' ?>>月榜</option>
                <option value="semester" <?= $period=='semester'?'selected':'' ?>>学期榜</option>
            </select>
            <select id="grade-filter" onchange="loadRanking()">
                <option value="">所有年级</option>
                <?php
                $grades = $pdo->query("SELECT * FROM grades")->fetchAll();
                foreach ($grades as $g) echo "<option value='".(int)$g['id']."'>".htmlspecialchars($g['name'], ENT_QUOTES)."</option>";
                ?>
            </select>
        </div>
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

// 管理页面（仅超级管理员 + 系统管理员，但账号管理仅超级管理员）
if ($tab === 'admin' && canOperateSystem()) {
    ?>
    <div class="grid-2">
        <?php if (isSuperAdmin()): ?>
        <div class="card">
            <h3>👥 管理员管理喵</h3>
            <p style="color:#64748b;">管理登录账户及角色分配喵（仅超级管理员可用）</p>
            <div class="btn-row"><a href="admin_users.php" class="btn">管理账户喵</a></div>
        </div>
        <?php endif; ?>
        <div class="card">
            <h3>🔐 安全设置</h3>
            <p style="color:#64748b; font-size:0.85rem;">启用身份验证器二次验证，增强账户安全</p>
            <div id="totp-status" style="margin-bottom:0.8rem;"></div>
            <button type="button" class="btn" onclick="showTotpSetup()" id="totp-setup-btn" style="display:none;">设置二次验证</button>
            <button type="button" class="btn btn-red" onclick="disableTotp()" id="totp-disable-btn" style="display:none;">禁用二次验证</button>
            <div id="totp-setup-area" style="display:none; margin-top:0.8rem;"></div>
        </div>
        <div class="card">
            <h3>📅 学期管理喵</h3>
            <p style="color:#64748b; font-size:0.85rem;">添加学期后喵，请点击"设为当前"激活喵</p>
            <form id="semester-form">
                <input type="text" name="name" placeholder="学期名称喵" required>
                <input type="date" name="start_date" required>
                <input type="date" name="end_date" required>
                <div class="btn-row"><button type="button" class="btn" onclick="addSemester()">添加学期喵</button></div>
            </form>
            <div id="semester-list" style="margin-top:1rem;"></div>
        </div>
        <div class="card">
            <h3>💾 数据备份与恢复喵</h3>
            <p style="color:#64748b; font-size:0.85rem;">导出完整备份或上传 SQL 恢复喵</p>
            <div class="btn-row"><a href="backup.php?action=export" class="btn">导出备份喵</a></div>
            <form id="restore-form" enctype="multipart/form-data" style="margin-top:0.5rem;">
                <input type="file" name="backup_file" accept=".sql">
                <div class="btn-row"><button type="button" class="btn" onclick="restoreBackup()">恢复喵</button></div>
            </form>
        </div>
        <div class="card">
            <h3>🏷️ 自定义奖惩类型喵</h3>
            <form id="type-form">
                <input type="text" name="type_name" placeholder="类型名称喵" required>
                <input type="text" name="category" placeholder="分类喵（如：卫生、纪律）">
                <select name="type_category">
                    <option value="punish">惩罚喵</option>
                    <option value="reward">奖励喵</option>
                </select>
                <input type="number" step="0.1" name="default_points" placeholder="默认分值喵" required>
                <div class="btn-row"><button type="button" class="btn" onclick="addType()">添加喵</button></div>
            </form>
            <hr>
            <div id="type-list"></div>
        </div>
        <div class="card grid-full">
            <h3>🏫 班级管理喵</h3>
            <form id="class-form">
                <select name="grade_id" required>
                    <option value="">选择年级喵</option>
                    <?php
                    $grades = $pdo->query("SELECT * FROM grades")->fetchAll();
                    foreach ($grades as $g) echo "<option value='".(int)$g['id']."'>".htmlspecialchars($g['name'], ENT_QUOTES)."</option>";
                    ?>
                </select>
                <input type="text" name="class_name" placeholder="班级名称喵" required>
                <input type="text" name="class_leader" placeholder="负责人姓名喵">
                <div class="btn-row"><button type="button" class="btn" onclick="addClass()">添加班级喵</button></div>
            </form>
            <hr>
            <h4>📥 批量导入喵</h4>
            <p style="font-size:0.8rem;"><a href="template/class_import_template.csv" download>下载 CSV 模板喵</a></p>
            <form id="import-form" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required>
                <div class="btn-row"><button type="button" class="btn" onclick="importClasses()">上传导入喵</button></div>
            </form>
            <div id="import-result" style="font-size:0.9rem; margin-top:0.5rem;"></div>
            <hr>
            <h4>📋 现有班级喵</h4>
            <div id="class-list"></div>
        </div>
    </div>

    <!-- 服务器状态 -->
    <div class="card grid-full">
        <h3>🖥️ 服务器状态喵</h3>
        <div class="server-status">
            <div class="ss-item"><label>PHP 版本</label><span><?= PHP_VERSION ?></span></div>
            <div class="ss-item"><label>MySQL 版本</label><span><?= htmlspecialchars((string)$pdo->query("SELECT VERSION()")->fetchColumn(), ENT_QUOTES) ?></span></div>
            <div class="ss-item"><label>操作系统</label><span><?= htmlspecialchars(PHP_OS . ' ' . php_uname('r'), ENT_QUOTES) ?></span></div>
            <div class="ss-item"><label>Web 服务器</label><span><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? '未知', ENT_QUOTES) ?></span></div>
            <div class="ss-item"><label>磁盘剩余</label><span><?php $d = @disk_free_space(__DIR__); echo $d === false ? '不可用' : number_format($d / 1073741824, 1) . ' GB'; ?></span></div>
            <div class="ss-item"><label>磁盘总量</label><span><?php $d = @disk_total_space(__DIR__); echo $d === false ? '不可用' : number_format($d / 1073741824, 1) . ' GB'; ?></span></div>
            <div class="ss-item"><label>上传限制</label><span><?= htmlspecialchars(ini_get('upload_max_filesize'), ENT_QUOTES) ?></span></div>
            <div class="ss-item"><label>PHP 内存</label><span><?= htmlspecialchars(ini_get('memory_limit'), ENT_QUOTES) ?></span></div>
            <div class="ss-item"><label>时区</label><span><?= htmlspecialchars(date_default_timezone_get(), ENT_QUOTES) ?></span></div>
        </div>
    </div>

    <!-- 操作日志卡片 -->
    <div class="card" style="margin-top:1rem;">
        <h3>📜 操作日志</h3>
        <div class="filter-bar">
            <select id="log-admin-filter">
                <option value="">所有管理员</option>
            </select>
            <input type="text" id="log-keyword" placeholder="搜索操作...">
            <div class="date-range-group">
                <input type="date" id="log-date-from">
                <span>~</span>
                <input type="date" id="log-date-to">
            </div>
            <button class="btn" onclick="loadLogs()">搜索</button>
        </div>
        <div id="log-list" style="max-height:500px; overflow-y:auto;"></div>
    </div>
    <script>
        // ========== 通用：发送 POST 请求并带 CSRF ==========
        async function apiPost(action, extraData = {}) {
            const data = new FormData();
            data.append('action', action);
            data.append('csrf_token', window._csrfToken);
            for (const [k, v] of Object.entries(extraData)) {
                if (v instanceof FileList) {
                    for (let i = 0; i < v.length; i++) data.append(k, v[i]);
                } else {
                    data.append(k, v);
                }
            }
            return fetch('api.php', { method: 'POST', body: data });
        }

        async function addSemester() {
            const form = document.getElementById('semester-form');
            const fd = new FormData(form);
            const res = await apiPost('add_semester', Object.fromEntries(fd.entries()));
            alert(await res.text());
            loadSemesters();
        }
        async function loadSemesters() {
            const res = await fetch('api.php?action=get_semesters');
            document.getElementById('semester-list').innerHTML = await res.text();
        }
        async function addType() {
            const form = document.getElementById('type-form');
            const fd = new FormData(form);
            const res = await apiPost('add_type', Object.fromEntries(fd.entries()));
            alert(await res.text());
            loadTypes();
        }
        async function loadTypes() {
            const res = await fetch('api.php?action=get_types');
            document.getElementById('type-list').innerHTML = await res.text();
        }
        async function restoreBackup() {
            const form = document.getElementById('restore-form');
            const fd = new FormData(form);
            const res = await apiPost('restore', Object.fromEntries(fd.entries()));
            alert(await res.text());
        }
        async function addClass() {
            const form = document.getElementById('class-form');
            const fd = new FormData(form);
            const res = await apiPost('add_class', Object.fromEntries(fd.entries()));
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
        loadTotpStatus();

        // ====== TOTP 二次验证 ======
        async function loadTotpStatus() {
            const res = await fetch('api.php?action=totp_status');
            const data = await res.json();
            const statusEl = document.getElementById('totp-status');
            const setupBtn = document.getElementById('totp-setup-btn');
            const disableBtn = document.getElementById('totp-disable-btn');
            if (data.enabled) {
                statusEl.innerHTML = '<span style="color:#16a34a;">已启用</span>';
                setupBtn.style.display = 'none';
                disableBtn.style.display = 'inline-block';
            } else {
                statusEl.innerHTML = '<span style="color:#94a3b8;">未启用</span>';
                setupBtn.style.display = 'inline-block';
                disableBtn.style.display = 'none';
            }
        }

        async function showTotpSetup() {
            const area = document.getElementById('totp-setup-area');
            area.style.display = 'block';
            const res = await fetch('api.php?action=totp_setup_info');
            const data = await res.json();
            area.innerHTML = `
                <div style="background:#f8fafc; padding:1rem; border-radius:0.8rem; text-align:center;">
                    <p style="font-size:0.9rem; margin-bottom:0.5rem;">1. 打开身份验证器App，扫描下方二维码</p>
                    <img src="${data.qr_url}" style="width:180px; height:180px; border-radius:0.5rem; margin-bottom:0.5rem;" alt="二维码">
                    <p style="font-size:0.85rem; color:#64748b; margin-bottom:0.5rem;">或手动输入密钥：</p>
                    <code style="display:inline-block; background:#e2e8f0; padding:0.3rem 0.6rem; border-radius:0.4rem; font-size:0.8rem; word-break:break-all;">${data.secret}</code>
                    <p style="font-size:0.85rem; color:#64748b; margin-top:0.5rem;">2. 输入身份验证器中显示的6位动态码确认</p>
                    <div style="display:flex; gap:0.5rem; margin-top:0.5rem; justify-content:center;">
                        <input type="text" id="totp-confirm-code" placeholder="000000" maxlength="6" style="width:120px; padding:0.5rem; border:2px solid #cbd5e1; border-radius:0.5rem; font-size:1.2rem; text-align:center; letter-spacing:0.3rem; outline:none;">
                        <button class="btn" onclick="confirmTotpEnable()">确认启用</button>
                    </div>
                    <p style="font-size:0.75rem; color:#94a3b8; margin-top:0.3rem;">验证成功后，下次登录需输入动态码</p>
                </div>
            `;
        }

        async function confirmTotpEnable() {
            const code = document.getElementById('totp-confirm-code').value;
            if (!code || !/^\d{6}$/.test(code)) {
                alert('请输入6位数字验证码喵');
                return;
            }
            const res = await apiPost('totp_enable', { code });
            const result = await res.json();
            alert(result.message);
            if (result.success) {
                document.getElementById('totp-setup-area').style.display = 'none';
                loadTotpStatus();
            }
        }

        async function disableTotp() {
            const password = prompt('请输入当前密码以禁用二次验证喵：');
            if (!password) return;
            const res = await apiPost('totp_disable', { password });
            const result = await res.json();
            alert(result.message);
            if (result.success) {
                loadTotpStatus();
            }
        }

        // 操作日志
        async function loadLogs() {
            const adminId = document.getElementById('log-admin-filter').value;
            const keyword = encodeURIComponent(document.getElementById('log-keyword').value);
            const dateFrom = document.getElementById('log-date-from').value;
            const dateTo = document.getElementById('log-date-to').value;
            let url = 'api.php?action=get_logs';
            if (adminId) url += '&admin_id=' + adminId;
            if (keyword) url += '&keyword=' + keyword;
            if (dateFrom) url += '&date_from=' + dateFrom;
            if (dateTo) url += '&date_to=' + dateTo;
            const res = await fetch(url);
            document.getElementById('log-list').innerHTML = await res.text();
        }
        async function loadLogAdminFilter() {
            const res = await fetch('api.php?action=get_admins');
            const select = document.getElementById('log-admin-filter');
            select.innerHTML = '<option value="">所有管理员</option>' + await res.text();
        }
        loadLogs();
        loadLogAdminFilter();
    </script>
    <?php
    exit;
}

// =================== POST 请求处理（全部要求 CSRF） ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // 所有带 action 的 POST 请求均需验证 CSRF（install 阶段由 install 单独处理）
    if (!empty($postAction)) {
        validateCsrf();
    }

    // ====== 权限检查（新角色逻辑）======
    $scoreOps = ['add_score', 'delete_record', 'import_classes'];
    $systemOps = ['add_semester', 'add_type', 'add_class', 'restore',
                  'set_current_semester', 'delete_class', 'freeze_class', 'unfreeze_class', 'delete_type'];
    $loginOnlyOps = array_merge($scoreOps, $systemOps, ['totp_enable', 'totp_disable']);

    if (in_array($postAction, $loginOnlyOps)) {
        requireLogin();
    }
    if (in_array($postAction, $scoreOps) && !canOperateScore()) {
        http_response_code(403); die("权限不足喵，无积分操作权限");
    }
    if (in_array($postAction, $systemOps) && !canOperateSystem()) {
        http_response_code(403); die("权限不足喵，无系统运维权限");
    }

    // 添加积分记录
    if ($postAction === 'add_score') {
        $semester = getCurrentSemester();
        if (!$semester) { http_response_code(400); die("❌ 请先设置当前学期喵。"); }

        $classId = $_POST['class_id'] ?? null;
        if (empty($classId)) { http_response_code(400); die("❌ 请选择班级喵"); }

        $typeId = $_POST['type_id'] ?? null;
        if (empty($typeId)) { http_response_code(400); die("❌ 请选择奖惩类型喵"); }

        $points = floatval($_POST['points'] ?? 0);
        $note = $_POST['note'] ?? '';

        $class = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $class->execute([$classId]);
        $class = $class->fetch();
        if (!$class) { http_response_code(404); die("班级不存在喵"); }
        if ($class['is_frozen']) { http_response_code(403); die("该班级已被冻结了喵，无法操作喵"); }

        // 图片上传
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ALLOWED_TYPES)) { http_response_code(415); die("不支持的文件类型喵，仅允许 JPG、PNG、GIF、WebP"); }
            if ($file['size'] > MAX_FILE_SIZE) { http_response_code(413); die("文件过大，最大允许 10MB，或者压缩图片喵"); }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = UPLOAD_DIR . $newName;
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $imagePath = UPLOAD_URL . $newName;
            } else {
                http_response_code(500);
                die("文件保存失败了喵");
            }
        }

        // 计算周次和月次（新算法）
        $week = getWeekNumber($semester['start_date']);
        $month = getMonthNumber($semester['start_date']);

        $stmt = $pdo->prepare("INSERT INTO score_records (class_id, type_id, points, admin_id, note, image_path, semester_id, week_number, month_number) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$classId, $typeId, $points, $_SESSION['admin_id'], $note, $imagePath, $semester['id'], $week, $month]);
        logAction('添加积分记录', 'class', $classId, "类型{$typeId} 分值{$points}");
        echo "操作成功喵";
        exit;
    }

    // 撤回记录
    if ($postAction === 'delete_record') {
        $recordId = intval($_POST['record_id'] ?? 0);
        $record = $pdo->prepare("SELECT sr.*, c.grade_id FROM score_records sr JOIN classes c ON sr.class_id = c.id WHERE sr.id = ?");
        $record->execute([$recordId]);
        $record = $record->fetch();
        if (!$record) { http_response_code(404); die("记录不存在喵"); }

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
        $name = $_POST['name'] ?? '';
        $start = $_POST['start_date'] ?? '';
        $end = $_POST['end_date'] ?? '';
        if (empty($name) || empty($start) || empty($end)) { http_response_code(400); die("请填写完整信息喵"); }
        $pdo->prepare("INSERT INTO semesters (name, start_date, end_date) VALUES (?,?,?)")->execute([$name, $start, $end]);
        logAction('添加学期喵', 'semester', null, $name);
        echo "学期添加成功喵";
        exit;
    }

    // ====== 设为当前学期（改为POST+CSRF）======
    if ($postAction === 'set_current_semester') {
        if (!isSuperAdmin()) { http_response_code(403); die("权限不足喵，仅超级管理员可切换学期"); }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); die("参数错误喵"); }
        $pdo->exec("UPDATE semesters SET is_current = 0");
        $pdo->prepare("UPDATE semesters SET is_current = 1 WHERE id = ?")->execute([$id]);
        logAction('切换当前学期喵', 'semester', $id);
        echo "已切换当前学期，请刷新页面喵。";
        exit;
    }

    // ====== 删除班级（改为POST+CSRF）======
    if ($postAction === 'delete_class') {
        $classId = intval($_POST['id'] ?? 0);
        $class = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $class->execute([$classId]);
        $class = $class->fetch();
        if (!$class) { http_response_code(404); die("班级不存在喵"); }
        $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$classId]);
        logAction('删除班级喵', 'class', $classId, $class['name']);
        echo "班级已删除喵";
        exit;
    }

    // ====== 冻结班级（改为POST+CSRF）======
    if ($postAction === 'freeze_class') {
        $classId = intval($_POST['id'] ?? 0);
        $class = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $class->execute([$classId]);
        $class = $class->fetch();
        if (!$class) { http_response_code(404); die("班级不存在喵"); }
        $pdo->prepare("UPDATE classes SET is_frozen = 1 WHERE id = ?")->execute([$classId]);
        logAction('冻结班级喵', 'class', $classId, $class['name']);
        echo "班级已冻结喵";
        exit;
    }

    // ====== 解冻班级（改为POST+CSRF）======
    if ($postAction === 'unfreeze_class') {
        $classId = intval($_POST['id'] ?? 0);
        $class = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $class->execute([$classId]);
        $class = $class->fetch();
        if (!$class) { http_response_code(404); die("班级不存在喵"); }
        $pdo->prepare("UPDATE classes SET is_frozen = 0 WHERE id = ?")->execute([$classId]);
        logAction('解冻班级喵', 'class', $classId, $class['name']);
        echo "班级已解冻喵";
        exit;
    }

    // 添加奖惩类型
    if ($postAction === 'add_type') {
        $name = $_POST['type_name'] ?? '';
        $cat = $_POST['type_category'] ?? 'punish';
        $points = floatval($_POST['default_points'] ?? 0);
        $category = $_POST['category'] ?? '';
        if (empty($name)) { http_response_code(400); die("请输入类型名称喵"); }
        $pdo->prepare("INSERT INTO reward_punish_types (name, type, category, default_points) VALUES (?,?,?,?)")
            ->execute([$name, $cat, $category, $points]);
        logAction('添加奖惩类型喵', 'type', null, $name);
        echo "类型添加成功喵";
        exit;
    }

    // ====== 删除奖惩类型（改为POST+CSRF）======
    if ($postAction === 'delete_type') {
        $typeId = intval($_POST['id'] ?? 0);
        $type = $pdo->prepare("SELECT * FROM reward_punish_types WHERE id = ?");
        $type->execute([$typeId]);
        $type = $type->fetch();
        if (!$type) { http_response_code(404); die("类型不存在喵"); }
        if ($type['is_builtin']) { http_response_code(403); die("内置类型不可删除喵"); }
        $check = $pdo->prepare("SELECT COUNT(*) FROM score_records WHERE type_id = ?");
        $check->execute([$typeId]);
        if ($check->fetchColumn() > 0) { http_response_code(409); die("该类型已被用于积分记录，无法删除喵"); }
        $pdo->prepare("DELETE FROM reward_punish_types WHERE id = ?")->execute([$typeId]);
        logAction('删除奖惩类型喵', 'type', $typeId, $type['name']);
        echo "删除成功喵";
        exit;
    }

    // 添加班级
    if ($postAction === 'add_class') {
        $grade_id = $_POST['grade_id'] ?? null;
        $name = trim($_POST['class_name'] ?? '');
        $leader = trim($_POST['class_leader'] ?? '');
        if (empty($grade_id) || empty($name)) { http_response_code(400); die("请填写完整信息喵"); }
        $pdo->prepare("INSERT INTO classes (grade_id, name, class_leader) VALUES (?,?,?)")->execute([$grade_id, $name, $leader]);
        logAction('添加班级喵', 'class', $pdo->lastInsertId(), $name);
        echo "班级添加成功喵";
        exit;
    }

    // 批量导入班级
    if ($postAction === 'import_classes') {
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) { http_response_code(400); die("文件上传失败喵"); }
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
                $errors[] = "第{$line}行：年级 '".htmlspecialchars($gradeName, ENT_QUOTES)."' 不存在";
                continue;
            }
            $check = $pdo->prepare("SELECT id FROM classes WHERE grade_id = ? AND name = ?");
            $check->execute([$grade['id'], $className]);
            if ($check->fetch()) {
                $errors[] = "第{$line}行：".htmlspecialchars($gradeName, ENT_QUOTES)." ".htmlspecialchars($className, ENT_QUOTES)." 已存在";
                continue;
            }
            $pdo->prepare("INSERT INTO classes (grade_id, name, class_leader) VALUES (?,?,?)")->execute([$grade['id'], $className, $leader]);
            $success++;
        }
        fclose($file);
        logAction('批量导入班级喵', 'class', null, "成功{$success}条，错误".count($errors));
        echo "导入完成喵：成功 {$success} 条喵。" . ($errors ? "错误信息喵：<br>" . implode('<br>', $errors) : "");
        exit;
    }

    // 恢复备份（加强校验）
    if ($postAction === 'restore') {
        if (!isSuperAdmin()) { http_response_code(403); die("权限不足喵，仅超级管理员可恢复备份"); }
        if ($_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            die("文件上传失败喵");
        }
        $fileTmp = $_FILES['backup_file']['tmp_name'];
        $fileName = $_FILES['backup_file']['name'];

        // 校验1：仅允许 .sql 扩展名
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            http_response_code(400);
            die("只允许上传 .sql 备份文件喵");
        }

        // 校验2：文件大小限制 50MB
        if ($_FILES['backup_file']['size'] > 50 * 1024 * 1024) {
            http_response_code(400);
            die("备份文件过大，最大允许 50MB 喵");
        }

        // 校验3：读取文件前 N 字节验证是否为 SQL 文本
        $handle = fopen($fileTmp, 'r');
        $header = fread($handle, 1024);
        fclose($handle);
        if ($header === false || trim($header) === '') {
            http_response_code(400);
            die("备份文件为空喵");
        }
        $upperHeader = strtoupper(substr(trim($header), 0, 200));
        if (strpos($upperHeader, 'CREATE TABLE') === false
            && strpos($upperHeader, 'INSERT INTO') === false
            && strpos($upperHeader, '--') === false
            && strpos($upperHeader, 'ALTER TABLE') === false) {
            http_response_code(400);
            die("文件格式无效，不是有效的 SQL 备份文件喵");
        }

        // 校验4：禁止危险操作（扩展关键字）
        $upperFull = strtoupper(file_get_contents($fileTmp));
        $dangerous = ['DROP DATABASE', 'DROP SCHEMA', 'TRUNCATE', 'DELETE FROM mysql',
                       'DROP TABLE mysql', 'CREATE USER', 'ALTER USER', 'REVOKE',
                       'SHUTDOWN', 'LOAD_FILE', 'INTO OUTFILE', 'INTO DUMPFILE'];
        foreach ($dangerous as $keyword) {
            if (strpos($upperFull, $keyword) !== false) {
                http_response_code(400);
                die("备份文件包含危险操作 ({$keyword})，拒绝执行喵");
            }
        }

        // 校验5：SQL 语句逐条执行，单条失败可追踪
        try {
            $sql = file_get_contents($fileTmp);
            // 分号拆分（简单拆分，跳过字符串中的分号）
            $statements = [];
            $inSingle = false;
            $inDouble = false;
            $current = '';
            for ($i = 0; $i < strlen($sql); $i++) {
                $ch = $sql[$i];
                if ($ch === "'" && ($i === 0 || $sql[$i-1] !== "\\")) $inSingle = !$inSingle;
                if ($ch === '"' && ($i === 0 || $sql[$i-1] !== "\\")) $inDouble = !$inDouble;
                if ($ch === ';' && !$inSingle && !$inDouble) {
                    $stmt = trim($current);
                    if ($stmt !== '') $statements[] = $stmt;
                    $current = '';
                } else {
                    $current .= $ch;
                }
            }
            $remain = trim($current);
            if ($remain !== '') $statements[] = $remain;

            $executed = 0;
            foreach ($statements as $st) {
                $st = trim($st);
                if ($st === '' || strpos($st, '--') === 0 || strpos($st, '/*') === 0) continue;
                $pdo->exec($st);
                $executed++;
            }
            logAction('恢复数据库备份喵', null, null, "执行{$executed}条语句");
            echo "恢复成功喵，共执行 {$executed} 条语句。";
        } catch (PDOException $e) {
            http_response_code(500);
            error_log("备份恢复失败: " . $e->getMessage());
            die("恢复失败喵：SQL 执行出错，请检查备份文件完整性喵。");
        }
        exit;
    }

    // 确认启用 TOTP
    if ($postAction === 'totp_enable' && isset($_SESSION['admin_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        $code = $_POST['code'] ?? '';
        $secret = $_SESSION['pending_totp_secret'] ?? '';
        if (empty($secret) || empty($code)) {
            echo json_encode(['success' => false, 'message' => '参数错误喵']);
            exit;
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            echo json_encode(['success' => false, 'message' => '验证码格式错误喵']);
            exit;
        }
        if (verifyTotp($secret, $code)) {
            $pdo->prepare("UPDATE admins SET totp_secret = ? WHERE id = ?")->execute([$secret, $_SESSION['admin_id']]);
            unset($_SESSION['pending_totp_secret']);
            logAction('启用二次验证喵');
            echo json_encode(['success' => true, 'message' => '二次验证已启用喵']);
        } else {
            echo json_encode(['success' => false, 'message' => '验证码错误，请重试喵']);
        }
        exit;
    }

    // 禁用 TOTP
    if ($postAction === 'totp_disable' && isset($_SESSION['admin_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => '请输入当前密码喵']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, $admin['password_hash'])) {
            $pdo->prepare("UPDATE admins SET totp_secret = NULL WHERE id = ?")->execute([$_SESSION['admin_id']]);
            logAction('禁用二次验证喵');
            echo json_encode(['success' => true, 'message' => '二次验证已禁用喵']);
        } else {
            echo json_encode(['success' => false, 'message' => '密码错误喵']);
        }
        exit;
    }
}

// =================== GET 数据处理（只读接口，不做写操作）===================

// 获取积分记录（image_path 转义修复）
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
    $sql .= " ORDER BY sr.created_at DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    $isScoreAdmin = !isGuest() && canOperateScore();
    echo "<table class='responsive-card' style='width:100%'><thead><tr><th>班级</th><th>类型</th><th>分值</th><th>时间</th><th>备注</th><th>附件</th>" . ($isScoreAdmin ? "<th>操作</th>" : "") . "</tr></thead><tbody>";
    foreach ($records as $r) {
        // image_path XSS 修复：所有用户可控/数据库字段均 HTML 转义
        $safeImg = htmlspecialchars($r['image_path'] ?? '', ENT_QUOTES, 'UTF-8');
        $attachHtml = !empty($r['image_path'])
            ? "<a href='{$safeImg}' target='_blank' rel='noopener'><img src='{$safeImg}' style='max-width:60px; max-height:40px; border-radius:4px;' alt='截图'></a>"
            : '—';
        $actionHtml = $isScoreAdmin ? "<button class='btn-sm btn-delete' onclick='deleteRecord({$r['id']})'>撤回</button>" : '';
        echo "<tr>
                <td data-label='班级'>".htmlspecialchars($r['grade_name'].$r['class_name'], ENT_QUOTES)."</td>
                <td data-label='类型'>".htmlspecialchars($r['type_name'], ENT_QUOTES)."</td>
                <td data-label='分值'>".htmlspecialchars($r['points'], ENT_QUOTES)."</td>
                <td data-label='时间'>".htmlspecialchars($r['created_at'], ENT_QUOTES)."</td>
                <td data-label='备注'>".htmlspecialchars($r['note'] ?? '', ENT_QUOTES)."</td>
                <td data-label='附件'>{$attachHtml}</td>" .
                ($isScoreAdmin ? "<td data-label='操作'>{$actionHtml}</td>" : "") .
              "</tr>";
    }
    echo "</tbody></table>";
    exit;
}

// 排行榜（MySQL 8.0+ 使用 CTE 优化查询，5.7 回退原生 JOIN）
if ($action === 'ranking') {
    $currentSemester = getCurrentSemester();
    if (!$currentSemester) { echo "<p>暂无当前学期数据</p>"; exit; }

    $period = $_GET['period'] ?? 'week';
    $gradeId = $_GET['grade_id'] ?? '';
    $useCTE = function_exists('isMySQL80') && isMySQL80();

    if ($useCTE) {
        // MySQL 8.0+：CTE 先聚合过滤后的积分，再 JOIN 班级信息，逻辑更清晰
        $cteFilter = '';
        $cteParams = [$currentSemester['id']];
        if ($period === 'week') {
            $tz = new DateTimeZone('Asia/Shanghai');
            $now = new DateTime('now', $tz);
            $weekMonday = clone $now;
            $dayOfWeek = (int)$now->format('N');
            if ($dayOfWeek > 1) $weekMonday->modify('-' . ($dayOfWeek - 1) . ' days');
            $weekMonday->setTime(0, 0, 0);
            $weekSunday = clone $weekMonday;
            $weekSunday->modify('+6 days')->setTime(23, 59, 59);
            $cteFilter = " AND sr.created_at BETWEEN ? AND ?";
            $cteParams[] = $weekMonday->format('Y-m-d H:i:s');
            $cteParams[] = $weekSunday->format('Y-m-d H:i:s');
        } elseif ($period === 'month') {
            $currentMonth = getMonthNumber($currentSemester['start_date']);
            list($monthStart, $monthEnd) = getMonthDateRange($currentSemester['start_date'], $currentMonth);
            $cteFilter = " AND sr.created_at BETWEEN ? AND ?";
            $cteParams[] = $monthStart->format('Y-m-d H:i:s');
            $cteParams[] = $monthEnd->format('Y-m-d H:i:s');
        }

        $sql = "WITH filtered_scores AS (
                    SELECT sr.class_id, SUM(sr.points) AS total
                    FROM score_records sr
                    WHERE sr.semester_id = ?{$cteFilter}
                    GROUP BY sr.class_id
                )
                SELECT c.id, c.name AS class_name, g.name AS grade_name, COALESCE(fs.total, 0) AS total, c.is_frozen
                FROM classes c
                JOIN grades g ON c.grade_id = g.id
                LEFT JOIN filtered_scores fs ON fs.class_id = c.id";
        $params = $cteParams;
    } else {
        // MySQL 5.7：原生 JOIN 聚合
        $sql = "SELECT c.id, c.name AS class_name, g.name AS grade_name, COALESCE(SUM(sr.points), 0) AS total, c.is_frozen
                FROM classes c
                JOIN grades g ON c.grade_id = g.id
                LEFT JOIN score_records sr ON sr.class_id = c.id AND sr.semester_id = ?";
        $params = [$currentSemester['id']];

        if ($period === 'week') {
            $tz = new DateTimeZone('Asia/Shanghai');
            $now = new DateTime('now', $tz);
            $weekMonday = clone $now;
            $dayOfWeek = (int)$now->format('N');
            if ($dayOfWeek > 1) $weekMonday->modify('-' . ($dayOfWeek - 1) . ' days');
            $weekMonday->setTime(0, 0, 0);
            $weekSunday = clone $weekMonday;
            $weekSunday->modify('+6 days')->setTime(23, 59, 59);
            $sql .= " AND sr.created_at BETWEEN ? AND ?";
            $params[] = $weekMonday->format('Y-m-d H:i:s');
            $params[] = $weekSunday->format('Y-m-d H:i:s');
        } elseif ($period === 'month') {
            $currentMonth = getMonthNumber($currentSemester['start_date']);
            list($monthStart, $monthEnd) = getMonthDateRange($currentSemester['start_date'], $currentMonth);
            $sql .= " AND sr.created_at BETWEEN ? AND ?";
            $params[] = $monthStart->format('Y-m-d H:i:s');
            $params[] = $monthEnd->format('Y-m-d H:i:s');
        }
    }

    if ($gradeId) {
        $sql .= " AND g.id = ?";
        $params[] = $gradeId;
    }

    $sql .= " GROUP BY c.id, c.name, g.name, c.is_frozen ORDER BY total DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ranking = $stmt->fetchAll();

    echo "<ol class='ranking-list'>";
    foreach ($ranking as $r) {
        $frozen = !empty($r['is_frozen']) ? '❄️' : '';
        $name = htmlspecialchars($r['grade_name'] . ' ' . $r['class_name'], ENT_QUOTES);
        echo "<li><span>{$name} {$frozen}</span><strong>".htmlspecialchars($r['total'], ENT_QUOTES)." 分</strong></li>";
    }
    echo "</ol>";
    exit;
}

// 导出积分明细（CSV 全部字段用 csvEscape 安全转义）
if ($action === 'export_records' && !isGuest() && canOperateScore()) {
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
    $sql .= " ORDER BY sr.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="score_records_'.date('YmdHis').'.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csvEscape', ['年级','班级','奖惩类型','类别','分值','时间','备注','图片路径'])) . "\n";
    foreach ($records as $r) {
        echo implode(',', [
            csvEscape($r['grade_name']),
            csvEscape($r['class_name']),
            csvEscape($r['type_name']),
            csvEscape($r['type_category']),
            csvEscape($r['points']),
            csvEscape($r['created_at']),
            csvEscape($r['note'] ?? ''),
            csvEscape($r['image_path'] ?? ''),
        ]) . "\n";
    }
    logAction('导出积分记录明细喵');
    exit;
}

// 导出积分汇总（班级总分）（CSV 全部字段用 csvEscape）
if ($action === 'export_scores' && !isGuest() && canOperateScore()) {
    $currentSemester = getCurrentSemester();
    if (!$currentSemester) die("无当前学期");

    $sql = "SELECT g.name AS grade_name, c.name AS class_name, c.class_leader, c.is_frozen,
                   COALESCE(SUM(sr.points), 0) AS total
            FROM classes c
            JOIN grades g ON c.grade_id = g.id
            LEFT JOIN score_records sr ON sr.class_id = c.id AND sr.semester_id = ?
            WHERE 1=1";
    $params = [$currentSemester['id']];
    $sql .= " GROUP BY c.id ORDER BY g.name, c.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="class_scores_'.date('YmdHis').'.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csvEscape', ['年级','班级','负责人','积分','状态'])) . "\n";
    foreach ($data as $row) {
        $status = $row['is_frozen'] ? '已冻结' : '正常';
        echo implode(',', [
            csvEscape($row['grade_name']),
            csvEscape($row['class_name']),
            csvEscape($row['class_leader'] ?? ''),
            csvEscape($row['total']),
            csvEscape($status),
        ]) . "\n";
    }
    logAction('导出班级积分喵');
    exit;
}

// 获取学期列表（设为当前 改为 POST，这里渲染按钮改成 form POST 提交）
if ($action === 'get_semesters') {
    $canSetCurrent = isSuperAdmin();
    $semesters = $pdo->query("SELECT * FROM semesters ORDER BY start_date DESC")->fetchAll();
    foreach ($semesters as $s) {
        $current = $s['is_current'] ? ' ✅ 当前喵' : '';
        $setBtn = '';
        if (!$s['is_current'] && $canSetCurrent) {
            $setBtn = "<form style='display:inline;' method='post' onsubmit='event.preventDefault(); apiPost(\"set_current_semester\", {id: {$s['id']}}).then(r=>r.text()).then(m=>{alert(m);loadSemesters();});'>
                        <input type='hidden' name='csrf_token' value='".generateCsrfToken()."'>
                        <button type='submit' class='btn-sm btn-green'>设为当前喵</button>
                       </form>";
        }
        echo "<div class='semester-row'>
                <span class='semester-info'>" . htmlspecialchars($s['name']) . " (" . htmlspecialchars($s['start_date']) . " ~ " . htmlspecialchars($s['end_date']) . ") {$current}</span>
                {$setBtn}
              </div>";
    }
    exit;
}

// 获取班级列表（管理）（冻结/解冻/删除改为 POST 提交）
if ($action === 'get_classes' && !isGuest() && canOperateSystem()) {
    $stmt = $pdo->query("SELECT c.*, g.name AS grade_name FROM classes c JOIN grades g ON c.grade_id = g.id ORDER BY g.id, c.name");
    $classes = $stmt->fetchAll();
    echo "<table class='responsive-card' style='width:100%'><thead><tr><th>年级</th><th>班级</th><th>负责人</th><th>状态</th><th>操作</th></tr></thead><tbody>";
    foreach ($classes as $c) {
        $frozenLabel = $c['is_frozen'] ? '❄️已冻结' : '✅正常';
        $freezeBtn = $c['is_frozen']
            ? "<form style='display:inline;' onsubmit='event.preventDefault(); apiPost(\"unfreeze_class\", {id: {$c['id']}}).then(r=>r.text()).then(m=>{alert(m);loadClasses();}); return false;'>
                 <button class='btn-sm btn-green' type='submit'>解冻</button></form>"
            : "<form style='display:inline;' onsubmit='event.preventDefault(); apiPost(\"freeze_class\", {id: {$c['id']}}).then(r=>r.text()).then(m=>{alert(m);loadClasses();}); return false;'>
                 <button class='btn-sm btn-red' type='submit'>冻结</button></form>";
        $delBtn = "<form style='display:inline;' onsubmit='event.preventDefault(); if(!confirm(\"确定删除该班级喵？相关积分记录将一并删除喵！\")) return false; apiPost(\"delete_class\", {id: {$c['id']}}).then(r=>r.text()).then(m=>{alert(m);loadClasses();}); return false;'>
                     <button class='btn-sm btn-delete' type='submit'>删除</button></form>";
        echo "<tr>
                <td data-label='年级'>".htmlspecialchars($c['grade_name'], ENT_QUOTES)."</td>
                <td data-label='班级'>".htmlspecialchars($c['name'], ENT_QUOTES)."</td>
                <td data-label='负责人'>".htmlspecialchars($c['class_leader'] ?? '—', ENT_QUOTES)."</td>
                <td data-label='状态'>{$frozenLabel}</td>
                <td data-label='操作'>{$delBtn} {$freezeBtn}</td>
              </tr>";
    }
    echo "</tbody></table>";
    exit;
}

// 获取奖惩类型列表（删除改为 POST）
if ($action === 'get_types' && !isGuest() && canOperateSystem()) {
    $types = $pdo->query("SELECT * FROM reward_punish_types ORDER BY type, category, name")->fetchAll();
    echo "<table class='responsive-card' style='width:100%'><thead><tr><th>分类</th><th>名称</th><th>类别</th><th>默认分值</th><th>操作</th></tr></thead><tbody>";
    foreach ($types as $t) {
        $catLabel = $t['type'] == 'punish' ? '惩罚' : '奖励';
        $category = htmlspecialchars($t['category'] ?? '—', ENT_QUOTES);
        $disabled = $t['is_builtin'] ? 'disabled' : '';
        $delBtn = $t['is_builtin']
            ? "<span style='color:#94a3b8; font-size:0.75rem;'>内置</span>"
            : "<form style='display:inline;' onsubmit='event.preventDefault(); if(!confirm(\"确定删除该类型喵？若已用于积分记录则无法删除喵。\")) return false; apiPost(\"delete_type\", {id: {$t['id']}}).then(r=>r.text()).then(m=>{alert(m);loadTypes();}); return false;'>
                 <button class='btn-sm btn-delete' type='submit' {$disabled}>删除</button></form>";
        echo "<tr>
                <td data-label='分类'>{$category}</td>
                <td data-label='名称'>".htmlspecialchars($t['name'], ENT_QUOTES)."</td>
                <td data-label='类别'>{$catLabel}</td>
                <td data-label='默认分值'>".htmlspecialchars($t['default_points'], ENT_QUOTES)."</td>
                <td data-label='操作'>{$delBtn}</td>
              </tr>";
    }
    echo "</tbody></table>";
    exit;
}

// 密保问题查询接口已移除：防止未登录用户枚举任意账户的密保问题（安全加固）
// 忘记密码请使用 install.php?action=reset 的密保验证流程喵

// =================== TOTP 二次验证接口 ===================

if ($action === 'totp_setup_info' && !isGuest()) {
    header('Content-Type: application/json; charset=utf-8');
    $username = $_SESSION['username'];
    $secret = generateTotpSecret();
    $uri = generateTotpUri($secret, $username);
    $_SESSION['pending_totp_secret'] = $secret;
    echo json_encode([
        'secret' => $secret,
        'uri' => $uri,
        'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . rawurlencode($uri)
    ]);
    exit;
}

if ($action === 'totp_status' && !isGuest()) {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $pdo->prepare("SELECT totp_secret FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    echo json_encode(['enabled' => !empty($admin['totp_secret'])]);
    exit;
}

// 获取管理员列表（用于日志过滤下拉）
if ($action === 'get_admins') {
    header('Content-Type: text/html; charset=utf-8');
    $rows = $pdo->query("SELECT id, username FROM admins ORDER BY username")->fetchAll();
    foreach ($rows as $r) {
        echo "<option value=\"{$r['id']}\">" . htmlspecialchars($r['username'], ENT_QUOTES) . "</option>";
    }
    exit;
}

// 获取操作日志
if ($action === 'get_logs' && !isGuest() && canOperateSystem()) {
    $adminId = $_GET['admin_id'] ?? '';
    $keyword = $_GET['keyword'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    $sql = "SELECT l.*, a.username FROM admin_logs l JOIN admins a ON l.admin_id = a.id WHERE 1=1";
    $params = [];

    if ($adminId) {
        $sql .= " AND l.admin_id = ?";
        $params[] = intval($adminId);
    }
    if ($keyword) {
        $sql .= " AND l.action LIKE ?";
        $params[] = '%' . $keyword . '%';
    }
    if ($dateFrom) {
        $sql .= " AND l.created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $sql .= " AND l.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    $sql .= " ORDER BY l.created_at DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    if (empty($logs)) {
        echo '<p style="color:#94a3b8;text-align:center;padding:1rem;">暂无操作日志</p>';
        exit;
    }

    echo '<div class="table-wrap"><table class="responsive-card" style="width:100%;font-size:0.85rem;">';
    echo '<thead><tr><th>时间</th><th>管理员</th><th>操作</th><th>类型</th><th>详情</th><th>IP</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $time = htmlspecialchars($log['created_at'], ENT_QUOTES);
        $user = htmlspecialchars($log['username'], ENT_QUOTES);
        $action = htmlspecialchars($log['action'], ENT_QUOTES);
        $targetType = htmlspecialchars($log['target_type'] ?? '—', ENT_QUOTES);
        $details = htmlspecialchars($log['details'] ?? '—', ENT_QUOTES);
        $ip = htmlspecialchars($log['ip'] ?? '—', ENT_QUOTES);
        echo "<tr>
                <td data-label='时间' style='white-space:nowrap;'>{$time}</td>
                <td data-label='管理员'>{$user}</td>
                <td data-label='操作'>{$action}</td>
                <td data-label='类型'>{$targetType}</td>
                <td data-label='详情' style='max-width:260px;word-break:break-all;'>{$details}</td>
                <td data-label='IP' style='font-size:0.75rem;color:#64748b;'>{$ip}</td>
              </tr>";
    }
    echo '</tbody></table></div>';
    exit;
}
?>
