<?php
require_once 'config.php';
logAction('退出系统');
session_destroy();
header('Location: index.php');