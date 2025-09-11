<?php
/**
 * 邮箱连接测试API
 * 支持代理连接测试和IMAP代理兼容性检查
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../utils/mail_fetcher.php';

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许POST请求'
    ]);
    exit();
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';

if (empty($email)) {
    echo json_encode([
        'success' => false,
        'message' => '请输入邮箱地址'
    ]);
    exit();
}

try {
    // 连接数据库查找邮箱配置
    $db = new SQLite3(__DIR__ . '/../../db/mail.sqlite');
    $stmt = $db->prepare('SELECT * FROM mail_accounts WHERE email = ?');
    $stmt->bindValue(1, $email);
    $result = $stmt->execute();
    $account = $result->fetchArray();
    
    if (!$account) {
        echo json_encode([
            'success' => false,
            'message' => '邮箱账号不存在，请联系管理员添加'
        ]);
        $db->close();
        exit();
    }
    
    // 创建邮件获取器实例
    $fetcher = new MailFetcher(
        $account['server'],
        $account['port'],
        $account['username'],
        $account['password'],
        $account['protocol'],
        $account['ssl'] == 1
    );
    
    // 测试连接
    $startTime = microtime(true);
    $testResult = $fetcher->testConnection();
    $responseTime = round((microtime(true) - $startTime) * 1000);
    
    // 获取代理兼容性信息
    $proxyCompatibility = $fetcher->checkProxyCompatibility();
    
    $response = [
        'success' => $testResult['success'],
        'message' => $testResult['message'],
        'response_time' => $responseTime,
        'diagnostics' => $testResult['diagnostics'] ?? [],
        'proxy_compatibility' => $proxyCompatibility
    ];
    
    // 添加连接详情
    $currentProxy = $fetcher->getCurrentProxy();
    if ($currentProxy) {
        $response['connection_details'] = [
            'proxy_detected' => true,
            'proxy_used' => false, // IMAP扩展不支持代理
            'proxy_info' => [
                'name' => $currentProxy['proxy_name'] ?? 'Unknown',
                'type' => $currentProxy['proxy_type'],
                'host' => $currentProxy['proxy_host'],
                'port' => $currentProxy['proxy_port']
            ],
            'connection_method' => 'direct',
            'proxy_limitation' => 'PHP IMAP扩展不支持代理，已自动使用直连'
        ];
    } else {
        $response['connection_details'] = [
            'proxy_detected' => false,
            'proxy_used' => false,
            'connection_method' => 'direct',
            'note' => '无可用代理，使用直连方式'
        ];
    }
    
    // 添加账户信息（隐藏敏感数据）
    $response['account_info'] = [
        'email' => $account['email'],
        'server' => $account['server'],
        'port' => $account['port'],
        'protocol' => strtoupper($account['protocol']),
        'ssl' => $account['ssl'] ? 'enabled' : 'disabled'
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $db->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage(),
        'error_type' => 'server_error',
        'proxy_note' => 'PHP IMAP扩展不支持代理连接'
    ]);
}
?>