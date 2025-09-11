<?php
// Add a test email account for demonstration
try {
    $db = new SQLite3('./db/mail.sqlite');
    
    // Check if test account already exists
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM mail_accounts WHERE email = ?');
    $stmt->bindValue(1, 'demo@example.com');
    $result = $stmt->execute();
    $row = $result->fetchArray();
    
    if ($row['count'] == 0) {
        // Add test account
        $stmt = $db->prepare('INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bindValue(1, 'demo@example.com');
        $stmt->bindValue(2, 'demo@example.com');
        $stmt->bindValue(3, 'demo_password');
        $stmt->bindValue(4, 'imap.example.com');
        $stmt->bindValue(5, 993);
        $stmt->bindValue(6, 'imap');
        $stmt->bindValue(7, 1);
        $stmt->bindValue(8, '演示账号，用于测试代理支持功能');
        $stmt->execute();
        
        echo "✅ 演示邮箱账号已添加: demo@example.com\n";
    } else {
        echo "✅ 演示邮箱账号已存在: demo@example.com\n";
    }
    
    // Show all accounts
    $result = $db->query('SELECT email, server, protocol, port, ssl, remarks FROM mail_accounts');
    echo "\n当前邮箱账号列表:\n";
    while ($row = $result->fetchArray()) {
        echo "- {$row['email']} ({$row['server']}:{$row['port']}, {$row['protocol']}" . ($row['ssl'] ? '+SSL' : '') . ")\n";
        if (!empty($row['remarks'])) {
            echo "  备注: {$row['remarks']}\n";
        }
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
}
?>