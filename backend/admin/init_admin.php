<?php
/**
 * 管理员初始化脚本
 * 初始化管理员账号和数据库
 */

// 创建数据库目录
if (!is_dir('../db')) {
    mkdir('../db', 0755, true);
}

// 初始化管理员数据库
$adminDb = new SQLite3('../db/admin.sqlite');
$adminDb->exec('CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// 初始化邮箱数据库
$mailDb = new SQLite3('../db/mail.sqlite');
$mailDb->exec('CREATE TABLE IF NOT EXISTS mail_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    username TEXT NOT NULL,
    password TEXT NOT NULL,
    server TEXT NOT NULL,
    port INTEGER NOT NULL,
    protocol TEXT NOT NULL CHECK(protocol IN ("imap", "pop3")),
    ssl BOOLEAN DEFAULT 0,
    remarks TEXT DEFAULT "",
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// 检查是否已存在管理员账号
$stmt = $adminDb->prepare('SELECT COUNT(*) as count FROM admins WHERE username = ?');
$stmt->bindValue(1, 'admin');
$result = $stmt->execute();
$row = $result->fetchArray();

if ($row['count'] == 0) {
    // 创建默认管理员账号
    $stmt = $adminDb->prepare('INSERT INTO admins (username, password) VALUES (?, ?)');
    $stmt->bindValue(1, 'admin');
    $stmt->bindValue(2, password_hash('admin', PASSWORD_DEFAULT));
    $stmt->execute();
    
    echo "管理员账号初始化成功！\n";
    echo "默认账号: admin\n";
    echo "默认密码: admin\n";
    echo "请访问 login.php 进行登录\n";
} else {
    echo "管理员账号已存在，无需重复初始化\n";
}

$adminDb->close();
$mailDb->close();
?>