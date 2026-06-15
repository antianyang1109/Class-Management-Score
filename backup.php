<?php
require_once 'config.php';
requireLogin();
if ($_SESSION['role'] !== 'super_admin') die('权限不足');

if ($_GET['action'] === 'export') {
    $tables = ['grades','classes','admins','semesters','reward_punish_types','score_records','admin_logs'];
    $sql = "";
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch();
        $sql .= $row['Create Table'] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
        foreach ($rows as $row) {
            $values = array_map(function($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote($v); }, array_values($row));
            $sql .= "INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n";
        }
        $sql .= "\n\n";
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="backup_'.date('YmdHis').'.sql"');
    echo $sql;
    logAction('导出数据库备份');
    exit;
}