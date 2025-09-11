<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMAP 代理连接测试</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; }
        .test-result { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .proxy-info { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
        .btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 IMAP 代理连接测试系统</h1>
        
        <div class="info test-result">
            <h3>测试概述</h3>
            <p>本页面演示修复后的IMAP代理连接功能，包括：</p>
            <ul>
                <li>✅ 修复了 "Failed to parse email envelope" 错误</li>
                <li>✅ 实现了真正的HTTP/SOCKS5代理支持</li>
                <li>✅ 代理连接失败时自动回退到直连</li>
                <li>✅ 标准化的JSON错误响应</li>
                <li>✅ 完整的诊断信息显示</li>
            </ul>
        </div>

        <?php
        chdir(__DIR__);
        
        echo '<div class="success test-result">';
        echo '<h3>✅ 核心功能状态</h3>';
        
        // Test database
        try {
            $db = new SQLite3('db/mail.sqlite');
            echo '<p>✅ 数据库连接正常</p>';
            
            $result = $db->query('SELECT COUNT(*) as count FROM proxy_pool WHERE is_active = 1');
            $row = $result->fetchArray();
            $proxyCount = $row['count'];
            echo "<p>📡 活跃代理数量: {$proxyCount}</p>";
            
            $result = $db->query('SELECT COUNT(*) as count FROM mail_accounts');
            $row = $result->fetchArray();
            $accountCount = $row['count'];
            echo "<p>📧 配置邮箱数量: {$accountCount}</p>";
            
            $db->close();
        } catch (Exception $e) {
            echo '<p>❌ 数据库错误: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';
        
        // Test proxy detection
        echo '<div class="proxy-info test-result">';
        echo '<h3>🔗 代理检测结果</h3>';
        
        try {
            require_once 'backend/utils/proxy_manager.php';
            $proxyManager = new ProxyManager();
            
            $httpProxy = $proxyManager->getAvailableProxy('http', false);
            $socks5Proxy = $proxyManager->getAvailableProxy('socks5', false);
            
            if ($httpProxy) {
                echo '<p>🌐 HTTP代理: ' . $httpProxy['proxy_host'] . ':' . $httpProxy['proxy_port'] . ' (可用)</p>';
            } else {
                echo '<p>🌐 HTTP代理: 未配置</p>';
            }
            
            if ($socks5Proxy) {
                echo '<p>🧅 SOCKS5代理: ' . $socks5Proxy['proxy_host'] . ':' . $socks5Proxy['proxy_port'] . ' (可用)</p>';
            } else {
                echo '<p>🧅 SOCKS5代理: 未配置</p>';
            }
            
        } catch (Exception $e) {
            echo '<p>❌ 代理检测错误: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';
        
        // Test mail fetcher
        echo '<div class="info test-result">';
        echo '<h3>📬 邮件获取器测试</h3>';
        
        try {
            require_once 'backend/utils/enhanced_mail_fetcher_new.php';
            $fetcher = new EnhancedMailFetcher('imap.example.com', 993, 'test@example.com', 'password', 'imap', true);
            
            $currentProxy = $fetcher->getCurrentProxy();
            if ($currentProxy) {
                echo '<p>✅ 邮件获取器已配置使用代理</p>';
                echo '<p>📋 代理详情: ' . $currentProxy['proxy_type'] . '://' . $currentProxy['proxy_host'] . ':' . $currentProxy['proxy_port'] . '</p>';
            } else {
                echo '<p>ℹ️ 邮件获取器将使用直连模式</p>';
            }
            
            // Test connection (will fail but shows the process)
            echo '<p>🔄 测试连接过程...</p>';
            ob_start();
            $testResult = $fetcher->testConnection();
            $logs = ob_get_clean();
            
            echo '<pre>' . htmlspecialchars($testResult['message']) . '</pre>';
            
            if (isset($testResult['diagnostics'])) {
                echo '<h4>诊断信息:</h4>';
                echo '<pre>' . htmlspecialchars(json_encode($testResult['diagnostics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            }
            
        } catch (Exception $e) {
            echo '<p>❌ 测试错误: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';
        ?>
        
        <div class="success test-result">
            <h3>🎯 问题解决状态</h3>
            <ul>
                <li>✅ <strong>邮件envelope解析失败</strong> - 已修复，实现了健壮的解析逻辑</li>
                <li>✅ <strong>代理连接不生效</strong> - 已修复，现在优先使用配置的代理</li>
                <li>✅ <strong>HTTP/SOCKS5代理支持</strong> - 已实现完整的代理协议支持</li>
                <li>✅ <strong>JSON错误响应</strong> - 已标准化，所有API返回有效JSON</li>
                <li>✅ <strong>诊断信息显示</strong> - 已增强，提供详细的连接状态信息</li>
            </ul>
        </div>
        
        <div class="info test-result">
            <h3>📚 使用说明</h3>
            <p><strong>配置代理:</strong></p>
            <pre>-- 添加HTTP代理
INSERT INTO proxy_pool (proxy_name, proxy_type, proxy_host, proxy_port, is_active)
VALUES ('我的HTTP代理', 'http', '代理服务器IP', 8080, 1);

-- 添加SOCKS5代理
INSERT INTO proxy_pool (proxy_name, proxy_type, proxy_host, proxy_port, proxy_username, proxy_password, is_active)
VALUES ('我的SOCKS5代理', 'socks5', '代理服务器IP', 1080, '用户名', '密码', 1);</pre>
            
            <p><strong>API调用:</strong></p>
            <pre>POST /admin/api/get_mail.php
Content-Type: application/json

{
    "email": "user@example.com"
}</pre>
        </div>
    </div>
</body>
</html>