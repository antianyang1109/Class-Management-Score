# 校园班级管理分系统 · Code Wiki

> 一套适用于校园班级（整体）周流动分管理的轻量级 PHP/MySQL 网站。
> 本文档由源码静态分析生成，覆盖整体架构、模块职责、关键函数、数据模型、依赖关系与运行方式。

---

## 目录

1. [项目概览](#1-项目概览)
2. [整体架构](#2-整体架构)
3. [目录结构](#3-目录结构)
4. [模块职责详解](#4-模块职责详解)
5. [数据模型（数据库表）](#5-数据模型数据库表)
6. [关键函数与接口说明](#6-关键函数与接口说明)
7. [请求流转与页面加载机制](#7-请求流转与页面加载机制)
8. [权限与角色体系](#8-权限与角色体系)
9. [安全机制](#9-安全机制)
10. [依赖关系](#10-依赖关系)
11. [项目运行方式](#11-项目运行方式)
12. [已知约束与注意事项](#12-已知约束与注意事项)

---

## 1. 项目概览

| 项目 | 说明 |
| --- | --- |
| 名称 | 校园班级管理分系统（班级积分系统） |
| 类型 | 单体 PHP Web 应用（无前端框架、无后端框架） |
| 技术栈 | PHP 7.4+ / MySQL 5.7+ / PDO / 原生 HTML+CSS+JS |
| 部署形态 | 直接将源码目录放置于 Web 服务器根目录 |
| 核心能力 | 班级积分增减、周/月/学期排行、角色权限隔离、数据备份恢复、操作日志、二次验证（TOTP）、图片上传、CSV 批量导入 |
| 开发说明 | 项目由 DeepSeek 辅助生成，README 自述"可能会有很多 BUG" |

---

## 2. 整体架构

系统采用经典的 **PHP 多入口页面 + 集中式 API 路由** 架构，无 MVC 分层，无依赖管理工具（无 `composer.json`）。

```
                    ┌─────────────────────────────────────┐
                    │           浏览器（前端）              │
                    │  index / install / dashboard /      │
                    │  totp_verify / admin_users          │
                    └──────────────┬──────────────────────┘
                                   │  HTTP（HTML/JSON/CSV/SQL）
                                   ▼
        ┌──────────────────────────────────────────────────┐
        │              Web 服务器（PHP 解释器）              │
        │                                                  │
        │   入口页面层        │   集中路由层        │  静态资源  │
        │   index.php        │   api.php          │  style.css│
        │   install.php      │   (tab=xxx 渲染    │  script.js│
        │   dashboard.php    │    + action=xxx    │           │
        │   admin_users.php  │    数据处理)        │           │
        │   totp_verify.php  │                    │           │
        │   backup.php       │                    │           │
        │   logout.php       │                    │           │
        └────────────┬─────────────────────────────┴──────────┘
                     │ require_once
                     ▼
        ┌──────────────────────────────────────────────────┐
        │                 config.php（核心）                │
        │  · DB 连接（PDO）                                 │
        │  · 会话启动                                       │
        │  · 鉴权助手：requireLogin / checkRole / isGuest   │
        │  · CSRF：generateCsrfToken / validateCsrf         │
        │  · 学期/周次：getCurrentSemester / getWeekNumber  │
        │  · 日志：logAction                                │
        │  · TOTP：base32 / generateTotpSecret / verifyTotp │
        │  · 自动数据库迁移（ALTER TABLE 兼容旧库）          │
        └────────────┬─────────────────────────────────────┘
                     │ PDO
                     ▼
        ┌──────────────────────────────────────────────────┐
        │              MySQL（utf8mb4）                     │
        │  grades / classes / admins / semesters /          │
        │  reward_punish_types / score_records / admin_logs │
        └──────────────────────────────────────────────────┘
```

**架构特点：**

- **前后端不分离**：PHP 直接输出 HTML 片段，前端通过 `fetch` 拉取 `api.php` 返回的 HTML 片段并注入 `#tab-content`。
- **集中式 API**：`api.php` 一个文件承担所有 Tab 页面渲染与全部业务动作（POST/GET），通过 `$_GET['tab']` 与 `$_POST/$_GET['action']` 区分。
- **全局共享上下文**：所有页面 `require_once 'config.php'`，自动获得 `$pdo`、`session`、CSRF、鉴权函数与自动迁移逻辑。
- **会话驱动鉴权**：登录态、角色、`grade_id`/`class_id`、CSRF token、TOTP pending 状态均存于 `$_SESSION`。

---

## 3. 目录结构

```
/workspace
├── config.php                     # 核心：DB连接 + 全局辅助函数 + 自动迁移 + TOTP
├── index.php                      # 登录入口（含账户锁定、TOTP 跳转）
├── install.php                    # 首次安装 / 忘记密码（密保重置）
├── dashboard.php                  # 主仪表盘（Tab 容器，注入全局 JS 函数）
├── api.php                        # 集中式 API：Tab 渲染 + 全部业务动作
├── admin_users.php                # 管理员账户管理（仅 super_admin）
├── backup.php                     # 数据库导出（仅 super_admin）
├── totp_verify.php                # 二次验证页（登录后二次校验）
├── logout.php                     # 退出登录
├── gen_hash.php                   # 一次性密码哈希生成工具（用完应删除）
├── database.sql                   # 数据库结构与默认数据脚本
├── style.css                      # 全局样式
├── script.js                      # Tab 切换与 AJAX 加载逻辑
├── favicon.ico                    # 站点图标
├── README.md                      # 项目说明
├── template/
│   └── class_import_template.csv  # 班级批量导入 CSV 模板（年级,班级名称,负责人）
└── uploads/                       # 积分截图上传目录（运行时自动创建）
    └── 图片临时存储文件夹.txt
```

---

## 4. 模块职责详解

### 4.1 `config.php` — 全局核心

文件既是配置文件也是公共库，被所有 PHP 入口 `require_once`。加载时副作用：

1. `session_start()` 启动会话；
2. 设置时区 `Asia/Shanghai`；
3. 建立 PDO 连接（失败即 `die`）；
4. **自动数据库迁移**：检测 `admins.security_question`、`score_records.image_path`、`reward_punish_types.category`、`admins.totp_secret` 字段是否存在，缺失则 `ALTER TABLE` 补齐（兼容旧版本升级）。

提供的主要能力：鉴权、CSRF、学期/周次计算、日志、密保问题、TOTP（Base32 + HOTP/TOTP 实现）、文件上传常量、系统初始化检测。详见 [第 6 节](#6-关键函数与接口说明)。

### 4.2 `index.php` — 登录入口

- 系统未初始化时重定向到 `install.php`。
- 处理 `POST` 登录：CSRF 校验 → 查询 `admins` → 检查账户锁定（`lock_until`）→ `password_verify` →
  - 失败：`failed_attempts++`，达 `MAX_LOGIN_ATTEMPTS`(5) 次设置 `lock_until = now + 60s`；
  - 成功：重置失败次数；若启用 TOTP 则写入 `pending_2fa_admin_id` 并跳转 `totp_verify.php`，否则写入登录会话跳转 `dashboard.php`。
- 提供"游客查看"与"忘记密码"链接。

### 4.3 `install.php` — 安装与重置

承担两个职责，通过 `?action=` 区分：

- **首次安装**（`POST action=setup`）：校验系统未初始化、密码强度、密保问题合法性后，向 `admins` 插入一条 `role='super_admin'` 记录（首位管理员即系统管理员）。
- **忘记密码**（`?action=reset`）：通过 `api.php?action=get_security_question` 异步拉取密保问题 → 用户回答 → `password_verify` 校验答案哈希 → 通过后重置密码并清空锁定状态。
- 已初始化且非 reset 访问时显示"已安装"提示页。

### 4.4 `dashboard.php` — 主仪表盘

- **不强制登录**，游客可访问（仅可见"积分记录"与"排行榜"Tab）。
- 根据角色动态渲染顶部 Tab：`quick`（管理员）、`records`、`ranking`、`admin`（仅 super_admin）。
- 在 `<head>` 中内联输出全局 CSRF Token `window._csrfToken` 及若干全局 JS 函数（`submitQuickScore`、`deleteRecord`、`freezeClass`、`unfreezeClass`、`deleteClass`、`importClasses`、`deleteType`、`switchTypeCategory`、`updateQuickPoints`），供 Tab 内联脚本调用。
- 引入 `script.js` 负责点击 Tab 后从 `api.php` 拉取并注入 HTML（含 `<script>` 重新执行）。

### 4.5 `api.php` — 集中式 API 路由

单文件承载两类请求：

**(A) Tab 页面渲染**（`$_GET['tab']`，返回 HTML 片段）：

| `tab` | 可见角色 | 内容 |
| --- | --- | --- |
| `quick` | super_admin / grade_admin | 快捷积分操作表单（班级、奖惩大类、具体类型、分值、备注、截图） |
| `records` | 所有人 | 积分记录列表（按班级筛选，管理员可见导出/撤回） |
| `ranking` | 所有人 | 周/月/学期排行榜（按年级筛选） |
| `admin` | super_admin | 学期管理、奖惩类型、班级管理、批量导入、数据备份恢复、TOTP 设置、管理员入口 |

**(B) 业务动作**（`$_POST['action']` / `$_GET['action']`）：

- 需要学期的动作（`add_score`、`get_records`、`ranking`、`export_scores`、`export_records`）会先取当前学期，缺失则报错。
- 所有带 `action` 的 POST 均强制 `validateCsrf()`。
- `add_score` 含图片上传处理（MIME 校验、10MB 上限、随机文件名存入 `uploads/`）。
- `restore`（恢复备份）含多重校验：扩展名 `.sql`、≤50MB、内容头部含 SQL 关键字、禁用 `DROP DATABASE/DROP SCHEMA/TRUNCATE`。
- TOTP 接口：`totp_setup_info`（生成密钥+二维码 URL）、`totp_enable`、`totp_disable`、`totp_status`。

### 4.6 `admin_users.php` — 管理员账户管理

- 仅 `super_admin` 可访问。
- `POST add_user`：新增管理员，按角色清空对应 `grade_id`/`class_id`；密码强度校验；用户名唯一性校验。
- `?delete=ID`：删除管理员；**保护规则**——不能删除自己、不能删除用户名为 `admin` 的最高管理员。
- 列表展示所有管理员及其关联年级/班级、锁定状态。

### 4.7 `backup.php` — 数据库导出

- 仅 `super_admin`，`?action=export` 触发。
- 遍历固定表清单 `['grades','classes','admins','semesters','reward_punish_types','score_records','admin_logs']`，导出 `SHOW CREATE TABLE` + `INSERT` 语句，输出 `.sql` 文件下载。

### 4.8 `totp_verify.php` — 二次验证页

- 仅当 `$_SESSION['pending_2fa_admin_id']` 存在时可访问，否则回登录。
- `POST code`：6 位数字校验 → `verifyTotp()` → 通过则写入正式登录会话并跳转仪表盘。

### 4.9 `logout.php` — 退出

记录"退出系统"日志，清空 2FA pending 状态，销毁会话，回登录页。

### 4.10 `gen_hash.php` — 一次性工具

为运维生成 `password_hash` 哈希值，支持 CLI 与 Web 模式；Web 模式提供"自删除"按钮，**使用后必须删除**，否则存在安全隐患。

### 4.11 静态资源

- `style.css`：全局样式（登录卡片、仪表盘布局、Tab、表格、按钮、响应式 `@media`）。
- `script.js`：`DOMContentLoaded` 后绑定 Tab 点击事件，`loadTab()` 通过 `fetch` 拉 `api.php?tab=` 并将返回 HTML 注入 `#tab-content`，同时把内联 `<script>` 节点重新创建以触发执行；并导出全局 `exportRecords()`。

---

## 5. 数据模型（数据库表）

数据库名 `class_points`，字符集 `utf8mb4`。共 7 张表，均使用 `InnoDB`。

### 5.1 `grades` — 年级
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK AI | |
| `name` | varchar(50) | 年级名（默认插入高一/高二/高三） |

### 5.2 `classes` — 班级
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK AI | |
| `grade_id` | int FK→grades.id | 外键，`ON DELETE CASCADE` |
| `name` | varchar(100) | 班级名 |
| `class_leader` | varchar(50) | 班级负责人 |
| `is_frozen` | tinyint(1) | 是否冻结（冻结后禁止积分操作） |
| `created_at` | timestamp | 默认当前时间 |

### 5.3 `admins` — 管理员
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK AI | |
| `username` | varchar(50) UNIQUE | |
| `password_hash` | varchar(255) | `password_hash(PASSWORD_DEFAULT)` |
| `role` | enum('super_admin','grade_admin','class_teacher') | 角色 |
| `grade_id` | int FK→grades.id | 年级管理员所属年级（`ON DELETE SET NULL`） |
| `class_id` | int FK→classes.id | 班主任所属班级（`ON DELETE SET NULL`） |
| `failed_attempts` | int | 登录失败次数 |
| `lock_until` | datetime | 锁定截止时间 |
| `security_question` | varchar(255) | 密保问题（自动迁移字段） |
| `security_answer_hash` | varchar(255) | 密保答案哈希（自动迁移字段） |
| `totp_secret` | varchar(64) | TOTP 密钥（自动迁移字段） |
| `created_at` | timestamp | |

### 5.4 `semesters` — 学期
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK AI | |
| `name` | varchar(100) | |
| `start_date` / `end_date` | date | |
| `is_current` | tinyint(1) | 是否当前学期（全局唯一，切换时先全置 0） |

### 5.5 `reward_punish_types` — 奖惩类型
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK AI | |
| `name` | varchar(100) | |
| `type` | enum('reward','punish') | |
| `category` | varchar(50) | 分类（如卫生、纪律，自动迁移字段） |
| `default_points` | decimal(5,1) | 默认分值 |
| `is_builtin` | tinyint(1) | 内置类型不可删除 |

默认内置类型：迟到(-2)、旷课(-5)、作业未交(-1)、违纪行为(-3)、好人好事(+3)、课堂表现优秀(+2)。

### 5.6 `score_records` — 积分记录
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK AI | |
| `class_id` | int FK→classes.id | `ON DELETE CASCADE` |
| `type_id` | int FK→reward_punish_types.id | |
| `points` | decimal(5,1) | 实际使用的分值（可临时调整） |
| `admin_id` | int FK→admins.id | 操作人 |
| `note` | text | 备注 |
| `image_path` | varchar(255) | 截图 URL（自动迁移字段） |
| `semester_id` | int FK→semesters.id | |
| `week_number` | int | 学期内周次 |
| `month_number` | int | 学期内月次 |
| `created_at` | timestamp | |

### 5.7 `admin_logs` — 操作日志
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK AI | |
| `admin_id` | int FK→admins.id | `ON DELETE CASCADE` |
| `action` | varchar(100) | 动作描述 |
| `target_type` | varchar(50) | 目标类型（class/record/semester/type/admin…） |
| `target_id` | int | 目标 ID |
| `details` | text | 详情 |
| `ip` | varchar(45) | 操作 IP |
| `created_at` | timestamp | |

---

## 6. 关键函数与接口说明

### 6.1 `config.php` 关键函数

| 函数 | 签名 | 作用 |
| --- | --- | --- |
| `getCurrentSemester()` | `(): array\|false` | 查询 `is_current=1` 的学期 |
| `requireLogin()` | `(): void` | 未登录跳转 `index.php` |
| `checkRole($allowed)` | `(array $allowed): void` | 角色不在白名单则 `die('权限不足喵')` |
| `isGuest()` | `(): bool` | 是否未登录 |
| `logAction($action, $target_type=null, $target_id=null, $details=null)` | `(): void` | 写入 `admin_logs`，含 IP；游客不记录 |
| `getWeekNumber($semesterStartDate)` | `(string): int` | 以周一为周起始，计算当前日期在学期内的自然周次（≥1） |
| `generateCsrfToken()` | `(): string` | 生成/复用会话级 32 字节 hex token |
| `csrfField()` | `(): string` | 输出隐藏 `<input name="csrf_token">` |
| `validateCsrf()` | `(): void` | `hash_equals` 校验 POST token，失败返回 403 |
| `isSystemInitialized()` | `(): bool` | `admins` 表是否有记录 |
| `getSecurityQuestions()` | `(): array` | 返回 8 个预设密保问题 |
| `base32_decode($data)` / `base32_encode($data)` | `(string): string` | RFC4648 Base32 编解码（TOTP 用） |
| `generateTotpSecret()` | `(): string` | 16 字节随机数 → Base32 |
| `generateTotpUri($secret, $username)` | `(): string` | 生成 `otpauth://totp/...` URI |
| `verifyTotp($secret, $code)` | `(): bool` | HMAC-SHA1 TOTP 校验，容忍 ±1 个 30s 时间窗口 |

**关键常量**：`DB_HOST/DB_NAME/DB_USER/DB_PASS`、`MAX_LOGIN_ATTEMPTS=5`、`LOCKOUT_DURATION=60`、`UPLOAD_DIR`、`UPLOAD_URL`、`MAX_FILE_SIZE=10MB`、`ALLOWED_TYPES`（jpeg/png/gif/webp）。

### 6.2 `api.php` 接口清单

#### Tab 渲染（GET `?tab=`）
| tab | 鉴权 | 返回 |
| --- | --- | --- |
| `quick` | super_admin/grade_admin | 快捷操作表单 HTML |
| `records` | 公开 | 记录列表容器 HTML |
| `ranking` | 公开 | 排行榜容器 HTML |
| `admin` | super_admin | 管理面板 HTML（含内联 JS） |

#### POST 动作（均需 CSRF）
| action | 鉴权 | 作用 |
| --- | --- | --- |
| `add_score` | super_admin/grade_admin | 新增积分记录（含图片上传、计算周/月次） |
| `delete_record` | super_admin/grade_admin | 撤回积分记录（含删除关联图片） |
| `add_semester` | super_admin | 新增学期 |
| `add_type` | super_admin/grade_admin | 新增奖惩类型 |
| `add_class` | super_admin/grade_admin | 新增班级 |
| `import_classes` | super_admin/grade_admin | CSV 批量导入班级 |
| `restore` | super_admin | 恢复 SQL 备份（多重校验） |
| `totp_enable` | 已登录 | 验证动态码后启用 TOTP |
| `totp_disable` | 已登录 | 验证密码后禁用 TOTP |

#### GET 动作
| action | 鉴权 | 作用 |
| --- | --- | --- |
| `get_records` | 公开（游客可见） | 返回当前学期积分记录表格 HTML |
| `ranking` | 公开 | 返回周/月/学期排行 HTML |
| `export_records` | super_admin/grade_admin | 导出积分明细 CSV |
| `export_scores` | super_admin/grade_admin | 导出班级积分汇总 CSV |
| `get_semesters` | 公开 | 学期列表 HTML |
| `set_current` | super_admin | 切换当前学期 |
| `get_classes` | super_admin/grade_admin | 班级管理列表 HTML |
| `delete_class` | super_admin/grade_admin | 删除班级 |
| `get_types` | super_admin/grade_admin | 奖惩类型列表 HTML |
| `delete_type` | super_admin/grade_admin | 删除奖惩类型（内置/已用不可删） |
| `freeze_class` / `unfreeze_class` | super_admin/grade_admin | 冻结/解冻班级 |
| `get_security_question` | 公开 | 返回 JSON `{question}` 或 `{error}` |
| `totp_setup_info` | 已登录 | 返回 JSON `{secret, uri, qr_url}` |
| `totp_status` | 已登录 | 返回 JSON `{enabled: bool}` |

### 6.3 `dashboard.php` 内联全局 JS 函数

| 函数 | 作用 |
| --- | --- |
| `submitQuickScore()` | 提交快捷积分表单（FormData + CSRF） |
| `deleteRecord(id)` | 撤回积分记录 |
| `freezeClass(id)` / `unfreezeClass(id)` | 冻结/解冻 |
| `deleteClass(id)` | 删除班级 |
| `importClasses()` | CSV 批量导入 |
| `deleteType(id)` | 删除奖惩类型 |
| `switchTypeCategory()` | 切换奖惩大类，重建小类下拉 |
| `updateQuickPoints(select)` | 选中类型时同步默认分值到输入框 |

### 6.4 `script.js` 关键函数

| 函数 | 作用 |
| --- | --- |
| `loadTab(tabName)` | `fetch('api.php?action=get_tab&tab=...')` 拉取 HTML，注入 `#tab-content`，并重执行内联 `<script>` |
| `exportRecords()` | 触发积分明细 CSV 下载 |

> 注：`script.js` 调用的是 `api.php?action=get_tab&tab=...`，但 `api.php` 实际只识别 `tab` 参数（不校验 `action`），逻辑可正常工作。

---

## 7. 请求流转与页面加载机制

### 7.1 首次部署
```
浏览器 → index.php → isSystemInitialized()=false → 302 install.php
       → 填写超级管理员表单 → POST action=setup → 写入 admins 表 → 提示前往登录
```

### 7.2 登录（含 2FA）
```
index.php (POST 登录)
  ├─ 密码错误 → failed_attempts++ → (≥5 次锁定 60s)
  ├─ 密码正确且未启用 TOTP → 写 SESSION → 302 dashboard.php
  └─ 密码正确且启用 TOTP → 写 pending_2fa_admin_id → 302 totp_verify.php
                                   → POST code → verifyTotp() → 写 SESSION → 302 dashboard.php
```

### 7.3 仪表盘 Tab 加载
```
dashboard.php 输出骨架 + 内联全局 JS
  └─ script.js DOMContentLoaded
       └─ tabs[0].click() → loadTab(tab)
              └─ fetch('api.php?tab=xxx') → 返回 HTML 片段
                     └─ 注入 #tab-content，重执行内联 <script>
                            └─ 片段内 JS 再 fetch('api.php?action=xxx') 加载实际数据
```

### 7.4 业务写操作
```
Tab 内表单按钮 onclick → 全局 JS 函数（FormData 含 action + csrf_token）
  → POST api.php → validateCsrf() → requireLogin() → checkRole()
  → 执行业务（PDO 预处理）→ logAction() → echo 结果
```

---

## 8. 权限与角色体系

| 角色 | role 值 | 可见 Tab | 数据范围 | 特殊权限 |
| --- | --- | --- | --- | --- |
| 超级管理员 | `super_admin` | quick / records / ranking / admin | 全部 | 学期管理、奖惩类型、班级、管理员、备份恢复、TOTP |
| 年级管理员 | `grade_admin` | quick / records / ranking | 本年级 | 本年级班级/积分操作 |
| 班主任 | `class_teacher` | records / ranking | — | README 注明"暂时没有任何权限，充当访客账号" |
| 游客 | （未登录） | records / ranking | 全部（只读） | 通过 `dashboard.php` 直接进入 |

**数据隔离实现**：`api.php` 中 `getVisibleClasses()` 与各查询在 `grade_admin` 角色下追加 `WHERE c.grade_id = $_SESSION['grade_id']`。

**保护规则**：
- 用户名为 `admin` 的账号被视为最高管理员，**不可删除**；
- 首位安装的管理员为 `super_admin`（README 提示"无法删除"）；
- `class_teacher` 当前无实质权限（仅能查看，与游客等同）。

---

## 9. 安全机制

| 机制 | 实现位置 | 说明 |
| --- | --- | --- |
| CSRF | `config.php` + 所有 POST | 会话级 token，`hash_equals` 校验，所有带 `action` 的 POST 强制验证 |
| 密码强度 | `install.php` / `admin_users.php` | 至少 8 位，须含大小写字母 + 数字（前后端均校验） |
| 密码存储 | `config.php` | `password_hash(PASSWORD_DEFAULT)` + `password_verify` |
| 账户锁定 | `index.php` | 5 次失败锁定 60 秒（`MAX_LOGIN_ATTEMPTS` / `LOCKOUT_DURATION`） |
| 二次验证 | `config.php` + `totp_verify.php` + `api.php` | TOTP（HMAC-SHA1，30s 窗口，±1 容差） |
| 密保重置 | `install.php?action=reset` | 答案哈希校验，重置后清空锁定 |
| SQL 注入 | 全局 | PDO 预处理语句（`prepare` + `execute`），未发现拼接 SQL |
| 角色控制 | `requireLogin` / `checkRole` + 各接口内联判断 | 双重校验（函数 + SQL WHERE） |
| 文件上传 | `api.php` `add_score` | MIME 白名单、10MB 上限、随机文件名 |
| 备份恢复 | `api.php` `restore` | 扩展名/大小/内容关键字/危险语句四重校验 |
| 操作审计 | `logAction` | 记录 admin_id / action / target / details / IP |
| 自动迁移 | `config.php` | 旧库升级时自动 `ALTER TABLE` 补字段 |
| 一次性工具 | `gen_hash.php` | 提供自删除按钮，警告使用后必须删除 |

---

## 10. 依赖关系

### 10.1 模块依赖（require / include）

```
config.php  ◄── require_once ──  index.php
                              ──  install.php
                              ──  dashboard.php
                              ──  api.php
                              ──  admin_users.php
                              ──  backup.php
                              ──  totp_verify.php
                              ──  logout.php

dashboard.php  ── 引入 ──  style.css, script.js
api.php (tab=quick/records/ranking/admin) 输出 HTML 引用 style.css 样式类
install.php / dashboard.php 内部 fetch → api.php?action=get_security_question 等
```

### 10.2 外部依赖

| 依赖 | 用途 | 是否必需 |
| --- | --- | --- |
| PHP ≥ 7.4 | 运行时 | 是 |
| PHP PDO 扩展 + MySQL 驱动 | 数据库访问 | 是 |
| PHP `random_bytes` / `hash_hmac` | CSRF / TOTP | 是（PHP 7+ 内置） |
| MySQL ≥ 5.7 | 数据存储 | 是 |
| Web 服务器（Nginx/Apache + PHP-FPM 或 Apache mod_php） | 部署 | 是 |
| `api.qrserver.com`（外部） | TOTP 二维码图片生成 | 仅启用 TOTP 时 |
| 身份验证器 App（Google Authenticator 等） | TOTP 客户端 | 启用 2FA 时 |

### 10.3 表间外键依赖

```
classes.grade_id      ─► grades.id        (CASCADE)
admins.grade_id       ─► grades.id        (SET NULL)
admins.class_id       ─► classes.id       (SET NULL)
score_records.class_id    ─► classes.id   (CASCADE)
score_records.type_id     ─► reward_punish_types.id
score_records.admin_id    ─► admins.id
score_records.semester_id ─► semesters.id
admin_logs.admin_id       ─► admins.id    (CASCADE)
```

---

## 11. 项目运行方式

### 11.1 环境要求
- PHP 7.4+（启用 PDO + MySQL 驱动）
- MySQL 5.7+（utf8mb4）
- Web 服务器（推荐 Nginx + PHP-FPM 或 Apache + mod_php）

### 11.2 部署步骤
1. 将整个目录部署到 Web 服务器站点根目录。
2. 在 MySQL 中导入 `database.sql`（脚本含 `CREATE DATABASE class_points`）。
3. 编辑 `config.php`，填写 `DB_HOST / DB_NAME / DB_USER / DB_PASS`。
4. 确认 `uploads/` 目录可写（运行时也会自动创建）。
5. 浏览器访问 `index.php`，系统检测到未初始化会自动跳转 `install.php`。
6. 在 `install.php` 创建首位超级管理员（建议同时设置密保问题）。
7. 登录后进入"管理"Tab → "学期管理"，添加并"设为当前"学期，否则积分相关功能不可用。

### 11.3 关键 URL
| URL | 作用 |
| --- | --- |
| `/index.php` | 登录 |
| `/install.php` | 首次安装 |
| `/install.php?action=reset` | 忘记密码（密保重置） |
| `/dashboard.php` | 主仪表盘（游客可直接访问） |
| `/admin_users.php` | 管理员账户管理 |
| `/backup.php?action=export` | 导出 SQL 备份 |
| `/totp_verify.php` | 二次验证 |
| `/logout.php` | 退出 |
| `/gen_hash.php` | 一次性哈希工具（**用完必删**） |

### 11.4 数据备份与恢复
- **导出**：管理面板 → "数据备份与恢复" → "导出备份"，或直接访问 `backup.php?action=export`，生成 `backup_YYYYMMDDHHMMSS.sql`。
- **恢复**：管理面板 → 上传 `.sql` 文件 → 触发 `api.php POST action=restore`，经四重校验后 `$pdo->exec($sql)` 执行。

### 11.5 班级批量导入
1. 下载 `template/class_import_template.csv`。
2. 按格式填写：`年级,班级名称,负责人`（年级必须已存在于 `grades` 表）。
3. 管理面板 → "班级管理" → "批量导入" 上传，触发 `api.php POST action=import_classes`，返回成功/错误明细。

### 11.6 启用二次验证（TOTP）
1. 超级管理员登录 → 管理面板 → "安全设置" → "设置二次验证"。
2. `api.php?action=totp_setup_info` 生成密钥与二维码（外部 `api.qrserver.com` 渲染）。
3. 用身份验证器扫码，输入 6 位动态码 → `api.php POST action=totp_enable` 校验通过后写入 `admins.totp_secret`。
4. 下次登录密码校验通过后跳转 `totp_verify.php` 输入动态码。

---

## 12. 已知约束与注意事项

1. **班主任角色无权限**：README 明确指出 `class_teacher` 暂无任何权限，仅等同访客。
2. **首位超级管理员无法删除**：`admin_users.php` 通过用户名 `admin` 保护；但实际"首位"是 `install.php` 创建的账号，若其用户名不是 `admin`，则保护逻辑仅对名为 `admin` 的账号生效。
3. **学期是积分前置条件**：未设置当前学期时，`add_score / get_records / ranking / export_*` 会报错或无数据。
4. **周次计算基于上海时区**：`getWeekNumber()` 固定 `Asia/Shanghai`，跨时区部署需调整。
5. **月榜口径粗略**：`month_number = ceil((now - start_date) / 30天)`，按 30 天近似，非自然月；周榜则按自然周（周一到周日）精确计算。
6. **排行榜分页**：`get_records` 固定 `LIMIT 100`，超过 100 条记录不展示。
7. **备份恢复风险**：`$pdo->exec($sql)` 一次性执行整份 SQL，大文件或含分号语句可能受 `max_allowed_packet` / 超时限制；虽禁 `DROP DATABASE/TRUNCATE`，但仍需管理员审慎上传。
8. **二维码依赖外部服务**：TOTP 二维码由 `api.qrserver.com` 生成，内网/离线环境无法显示（可改用手动输入密钥）。
9. **`gen_hash.php` 安全隐患**：一次性工具若未及时删除，会泄露哈希生成能力，部署后建议立即删除。
10. **无 HTTPS 强制**：代码未强制 HTTPS，生产部署应在前端（Nginx/Apache）层配置 TLS。
11. **CSRF Token 全局共享**：单个会话内所有表单复用同一 token，刷新页面才更新。
12. **自动迁移静默失败**：`config.php` 中 `ALTER TABLE` 失败时 `catch` 后静默忽略，旧库若权限不足可能字段未补齐而无明显提示。

---

> 本 Wiki 基于源码静态阅读生成，反映了当前仓库的实现状态。后续若新增模块或调整接口，请同步更新本文档。
