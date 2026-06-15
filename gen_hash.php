<?php
$password = 'YourPass123';  // 请修改为你的强密码（至少8位，含大小写字母和数字）
echo password_hash($password, PASSWORD_DEFAULT);
?>