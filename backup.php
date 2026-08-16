<?php
require_once 'config.php';
requireLogin();
if ($_SESSION['role'] !== 'super_admin') { http_response_code(403); die('权限不足喵'); }

if ($_GET['action'] === 'export') {
    $tables = ['grades','classes','admins','semesters','reward_punish_types','score_records','admin_logs','db_migrations'];
    $sql = "-- 班级积分管理系统 · 数据库备份\n";
    $sql .= "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- MySQL 版本: " . getMySQLVersion() . "\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        // 检查表是否存在
        $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'")->fetchColumn();
        if (!$exists) continue;

        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch();
        $createSQL = $row['Create Table'];
        // MySQL 8.0 SHOW CREATE TABLE 输出可能包含额外属性（如 AUTO_INCREMENT 值），
        // 保留完整 DDL 以确保兼容性，移除表级 AUTO_INCREMENT 值避免冲突
        $createSQL = preg_replace('/ AUTO_INCREMENT=\d+/', '', $createSQL);
        $sql .= $createSQL . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $colNames = '`' . implode('`, `', $columns) . '`';
            foreach ($rows as $row) {
                $values = array_map(function($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    // JSON 类型在 MySQL 8.0 中返回字符串，直接 quote
                    return $pdo->quote($v);
                }, array_values($row));
                $sql .= "INSERT INTO `$table` ($colNames) VALUES (" . implode(',', $values) . ");\n";
            }
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup_'.date('YmdHis').'.sql"');
    echo $sql;
    logAction('导出数据库备份喵');
    exit;
}