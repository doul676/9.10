<?php
/**
 * 测试代理支持的邮件获取功能
 */

require_once './backend/utils/mail_fetcher_proxy.php';
require_once './backend/utils/proxy_manager.php';

echo "=== 代理支持邮件系统测试 ===\n\n";

// 1. 测试webklex/php-imap库是否正确安装
echo "1. 检查webklex/php-imap库...\n";
if (class_exists('Webklex\PHPIMAP\ClientManager')) {
    echo "✅ webklex/php-imap库已正确安装\n";
} else {
    echo "❌ webklex/php-imap库未安装或autoload失败\n";
    exit(1);
}

// 2. 测试代理管理器
echo "\n2. 检查代理管理器...\n";
try {
    $proxyManager = new ProxyManager();
    $proxies = $proxyManager->getAllAvailableProxies('', false);
    echo "✅ 代理管理器正常，当前代理池有 " . count($proxies) . " 个代理\n";
} catch (Exception $e) {
    echo "❌ 代理管理器测试失败: " . $e->getMessage() . "\n";
}

// 3. 测试MailFetcherProxy类实例化
echo "\n3. 测试MailFetcherProxy类...\n";
try {
    $fetcher = new MailFetcherProxy('imap.qq.com', 993, 'test@qq.com', 'testpass', 'imap', true);
    echo "✅ MailFetcherProxy类实例化成功\n";
} catch (Exception $e) {
    echo "❌ MailFetcherProxy类实例化失败: " . $e->getMessage() . "\n";
}

// 4. 测试数据库连接和代理池表
echo "\n4. 检查数据库代理池表...\n";
try {
    $db = new SQLite3('./db/mail.sqlite');
    $result = $db->query("SELECT COUNT(*) as count FROM proxy_pool");
    $row = $result->fetchArray();
    echo "✅ 代理池表存在，包含 " . $row['count'] . " 条记录\n";
    $db->close();
} catch (Exception $e) {
    echo "❌ 数据库代理池表检查失败: " . $e->getMessage() . "\n";
}

echo "\n=== 测试完成 ===\n";
echo "如果所有测试都通过，说明代理支持的邮件系统已准备就绪\n";
?>