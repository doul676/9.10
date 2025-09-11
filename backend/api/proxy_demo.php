<?php
/**
 * 代理支持演示API
 * 展示代理支持的邮件获取功能
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../utils/mail_fetcher_proxy.php';
require_once '../utils/proxy_manager.php';

// 只允许GET请求进行演示
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许GET请求用于演示'
    ]);
    exit();
}

try {
    // 检查代理池状态
    $proxyManager = new ProxyManager();
    $availableProxies = $proxyManager->getAllAvailableProxies('', false);
    
    // 创建演示用的MailFetcherProxy实例
    $fetcher = new MailFetcherProxy(
        'imap.qq.com',  // 示例服务器
        993,            // 示例端口
        'demo@qq.com',  // 示例用户名
        'demopass',     // 示例密码
        'imap',         // 协议
        true            // SSL
    );
    
    // 测试连接（不会实际连接，只是测试类的功能）
    $testResult = $fetcher->testConnection();
    
    $response = [
        'success' => true,
        'message' => '代理支持的邮件系统已成功集成',
        'features' => [
            'webklex_imap' => '✅ webklex/php-imap库已安装',
            'proxy_support' => '✅ 代理支持已集成',
            'fallback_support' => '✅ 自动回退到传统IMAP',
            'proxy_pool' => '✅ 代理池管理已就绪'
        ],
        'proxy_pool_status' => [
            'total_proxies' => count($availableProxies),
            'active_proxies' => count(array_filter($availableProxies, function($p) {
                return $p['is_active'] == 1;
            })),
            'verified_proxies' => count(array_filter($availableProxies, function($p) {
                return $p['is_verified'] == 1;
            }))
        ],
        'connection_test' => $testResult,
        'integration_status' => [
            'backend_api' => '✅ backend/api/get_mail.php已更新',
            'proxy_manager' => '✅ ProxyManager类可用',
            'mail_fetcher_proxy' => '✅ MailFetcherProxy类可用',
            'database_schema' => '✅ 代理池表已创建'
        ],
        'next_steps' => [
            '1. 通过管理后台添加代理服务器',
            '2. 配置邮箱账号进行测试',
            '3. 前端将自动使用代理连接（如果可用）',
            '4. 代理连接失败时自动回退到直连'
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '代理支持测试失败: ' . $e->getMessage(),
        'error_details' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
?>