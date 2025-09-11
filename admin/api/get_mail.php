<?php
/**
 * 邮件获取API
 * 为前端提供邮件获取服务
 */

// 防止PHP错误/警告污染JSON输出
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 开始输出缓冲以捕获任何意外输出
ob_start();

// 清理任何意外输出并设置响应头
if (ob_get_level()) {
    ob_clean();
}

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
    $db = new SQLite3('../../db/mail.sqlite');
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
    require_once '../../backend/utils/imap_check.php';
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
    
    // 检查代理池状态
    require_once '../../backend/utils/proxy_manager.php';
    $proxyManager = new ProxyManager();
    $availableProxy = $proxyManager->getAvailableProxy('', false); // 获取任何类型的可用代理
    
    // 创建邮件获取器实例
    $fetcher = new MailFetcher(
        $account['server'],
        $account['port'],
        $account['username'],
        $account['password'],
        $account['protocol'],
        $account['ssl'] == 1
    );
    
    // 连接并获取最新邮件
    $startTime = microtime(true);
    if ($fetcher->connect()) {
        $result = $fetcher->getLatestMail();
        $currentProxy = $fetcher->getCurrentProxy();
        $fetcher->close();
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($result['success']) {
            if ($result['mail']) {
                $responseData = [
                    'success' => true,
                    'message' => '邮件获取成功',
                    'mail' => $result['mail'],
                    'response_time' => $responseTime
                ];
                
                // 添加代理使用信息
                if ($currentProxy) {
                    $responseData['proxy'] = [
                        'used' => true,
                        'type' => $currentProxy['proxy_type'],
                        'host' => $currentProxy['proxy_host'],
                        'port' => $currentProxy['proxy_port'],
                        'name' => $currentProxy['proxy_name'] ?? 'Unknown'
                    ];
                } else {
                    $responseData['proxy'] = [
                        'used' => false,
                        'available' => $availableProxy !== null
                    ];
                }
                
                echo json_encode($responseData);
            } else {
                $responseData = [
                    'success' => true,
                    'message' => '邮箱中暂无邮件',
                    'mail' => null,
                    'response_time' => $responseTime
                ];
                
                // 添加代理使用信息
                if ($currentProxy) {
                    $responseData['proxy'] = [
                        'used' => true,
                        'type' => $currentProxy['proxy_type'],
                        'host' => $currentProxy['proxy_host'],
                        'port' => $currentProxy['proxy_port']
                    ];
                } else {
                    $responseData['proxy'] = [
                        'used' => false,
                        'available' => $availableProxy !== null
                    ];
                }
                
                echo json_encode($responseData);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'],
                'proxy' => $currentProxy ? [
                    'used' => true,
                    'type' => $currentProxy['proxy_type'],
                    'host' => $currentProxy['proxy_host'],
                    'port' => $currentProxy['proxy_port']
                ] : [
                    'used' => false,
                    'available' => $availableProxy !== null
                ]
            ]);
        }
    } else {
        // Connection failed - provide detailed diagnostic information
        $currentProxy = $fetcher->getCurrentProxy();
        $proxyAttempted = $fetcher->wasProxyAttempted();
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        $response = [
            'success' => false,
            'message' => '无法连接到邮件服务器，请检查邮箱配置。' . 
                        ($availableProxy ? ' 已尝试通过代理连接。' : ' 无可用代理，已尝试直连。'),
            'response_time' => $responseTime,
            'error_type' => 'connection_failed',
            'diagnostics' => [
                'server' => $account['server'] . ':' . $account['port'],
                'protocol' => strtoupper($account['protocol']) . ($account['ssl'] ? ' with SSL' : ''),
                'suggestion' => '请检查服务器地址、端口、用户名密码是否正确，或者网络连接是否正常'
            ]
        ];
        
        // 添加代理使用信息
        if ($proxyAttempted && $availableProxy) {
            // We attempted proxy but it failed
            $response['proxy'] = [
                'used' => false,
                'attempted' => true,
                'available' => true,
                'type' => $availableProxy['proxy_type'],
                'host' => $availableProxy['proxy_host'],
                'port' => $availableProxy['proxy_port'],
                'name' => $availableProxy['proxy_name'] ?? 'Unknown',
                'failed' => true,
                'message' => '代理连接失败，已回退到直连'
            ];
        } elseif ($currentProxy) {
            $response['proxy'] = [
                'used' => true,
                'type' => $currentProxy['proxy_type'],
                'host' => $currentProxy['proxy_host'],
                'port' => $currentProxy['proxy_port'],
                'name' => $currentProxy['proxy_name'] ?? 'Unknown',
                'failed' => true
            ];
        } else {
            $response['proxy'] = [
                'used' => false,
                'available' => $availableProxy !== null,
                'message' => $availableProxy ? '代理可用但未使用' : '无可用代理，使用直连'
            ];
        }
        
        echo json_encode($response);
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}
?>