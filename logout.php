<?php
require_once 'config.php';
logAction('退出系统喵');
unset($_SESSION['pending_2fa_admin_id'], $_SESSION['pending_2fa_username']);
session_destroy();
header('Location: index.php');