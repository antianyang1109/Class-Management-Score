<?php
/**
 * 密码哈希生成工具（一次性）
 * 
 * 使用方法：
 * 1. 修改下方 $password 为你的目标密码
 * 2. 通过浏览器或命令行运行此文件生成哈希
 * 3. 将输出的哈希值存入数据库
 * 4. ⚠️ 生成后请立即删除此文件！
 */

$password = '入侵服务器？何意味？';

if (php_sapi_name() === 'cli') {
    // 命令行模式
    echo "生成的密码哈希：\n";
    echo password_hash($password, PASSWORD_DEFAULT) . "\n\n";
    echo "⚠️ 请复制上面的哈希值后，立即删除此文件！\n";
} else {
    // Web 模式
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
        if (unlink(__FILE__)) {
            die('<h2>✅ gen_hash.php 已成功自删除。</h2><p>请将此哈希值存入数据库。</p>');
        } else {
            die('<h2>❌ 自动删除失败，请手动删除此文件。</h2>');
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>密码哈希生成</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 600px; margin: 2rem auto; padding: 0 1rem; }
            .hash-box { background: #f0fdf4; border: 2px solid #15803d; border-radius: 0.8rem; padding: 1rem; 
                        word-break: break-all; font-family: monospace; margin: 1rem 0; }
            .warning { background: #fef3c7; border: 1px solid #d97706; border-radius: 0.8rem; padding: 1rem; 
                       color: #92400e; margin: 1rem 0; }
            button { background: #dc2626; color: white; border: none; padding: 0.8rem 2rem; border-radius: 0.8rem; 
                     font-size: 1rem; cursor: pointer; }
            button:hover { background: #b91c1c; }
        </style>
    </head>
    <body>
        <h1>🔐 密码哈希生成器</h1>
        <div class="warning">
            ⚠️ <strong>安全警告：</strong>此文件为一次性工具。生成哈希后必须立即删除，否则会造成严重安全隐患！
        </div>
        <p>目标密码生成的哈希值：</p>
        <div class="hash-box"><?= htmlspecialchars($hash) ?></div>
        <p>📋 请先复制上面的哈希值，然后点击下方按钮自动删除此文件：</p>
        <form method="post" onsubmit="return confirm('确定已复制哈希值？此文件将被永久删除。')">
            <input type="hidden" name="confirm_delete" value="1">
            <button type="submit">🗑️ 我已复制哈希值，立即删除此文件</button>
        </form>
    </body>
    </html>
    <?php
}
