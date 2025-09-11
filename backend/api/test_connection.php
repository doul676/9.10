<?php
/**
 * 邮箱连接测试API
 * 支持代理连接测试
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
$useProxy = $input['useProxy'] ?? false;

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
        $account['ssl'] == 1,
        $useProxy
    );
    
    // 测试连接
    $startTime = microtime(true);
    $testResult = $fetcher->testConnection();
    $responseTime = round((microtime(true) - $startTime) * 1000);
    
    $response = [
        'success' => $testResult['success'],
        'message' => $testResult['message'],
        'response_time' => $responseTime,
        'diagnostics' => $testResult['diagnostics'] ?? []
    ];
    
    // 如果使用了代理，添加代理信息
    if ($useProxy) {
        $proxyInfo = $fetcher->getCurrentProxy();
        if ($proxyInfo) {
            $response['proxy'] = [
                'used' => true,
                'type' => $proxyInfo['proxy_type'],
                'host' => $proxyInfo['proxy_host'],
                'port' => $proxyInfo['proxy_port'],
                'name' => $proxyInfo['proxy_name'] ?: '未命名代理',
                'verified' => $proxyInfo['is_verified'] == 1
            ];
        } else {
            $response['proxy'] = [
                'used' => false,
                'message' => '没有可用的代理服务器'
            ];
        }
    } else {
        $response['proxy'] = ['used' => false];
    }
    
    echo json_encode($response);
    $db->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage(),
        'proxy' => ['used' => $useProxy]
    ]);
}
?>