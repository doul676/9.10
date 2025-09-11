<?php
/**
 * 邮件获取API
 * 为前端提供邮件获取服务
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
$useProxy = $input['useProxy'] ?? false; // 是否使用代理

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
    
    // 使用完整的IMAP扩展检查函数
    require_once '../utils/imap_check.php';
    $imapInfo = checkImapExtension();
    
    if (!$imapInfo['available']) {
        echo json_encode([
            'success' => false,
            'message' => $imapInfo['message'],
            'diagnostics' => $imapInfo['diagnostics'],
            'error_type' => 'extension_issue'
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
        $useProxy  // 传递代理使用标志
    );
    
    // 连接并获取最新邮件
    if ($fetcher->connect()) {
        $result = $fetcher->getLatestMail();
        $proxyInfo = $fetcher->getCurrentProxy();
        $fetcher->close();
        
        if ($result['success']) {
            if ($result['mail']) {
                $response = [
                    'success' => true,
                    'message' => '邮件获取成功',
                    'mail' => $result['mail']
                ];
                
                // 如果使用了代理，添加代理信息
                if ($useProxy && $proxyInfo) {
                    $response['proxy'] = [
                        'used' => true,
                        'type' => $proxyInfo['proxy_type'],
                        'host' => $proxyInfo['proxy_host'],
                        'port' => $proxyInfo['proxy_port'],
                        'name' => $proxyInfo['proxy_name'] ?: '未命名代理'
                    ];
                } else {
                    $response['proxy'] = ['used' => false];
                }
                
                echo json_encode($response);
            } else {
                $response = [
                    'success' => true,
                    'message' => '邮箱中暂无邮件',
                    'mail' => null
                ];
                
                // 如果使用了代理，添加代理信息
                if ($useProxy && $proxyInfo) {
                    $response['proxy'] = [
                        'used' => true,
                        'type' => $proxyInfo['proxy_type'],
                        'host' => $proxyInfo['proxy_host'],
                        'port' => $proxyInfo['proxy_port'],
                        'name' => $proxyInfo['proxy_name'] ?: '未命名代理'
                    ];
                } else {
                    $response['proxy'] = ['used' => false];
                }
                
                echo json_encode($response);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message']
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => '无法连接到邮件服务器，请检查邮箱配置'
        ]);
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}
?>