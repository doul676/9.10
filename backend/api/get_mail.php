<?php
/**
 * 邮件获取API
 * 为前端提供邮件获取服务，自动使用代理池（如果可用）
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
require_once '../utils/proxy_manager.php';

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
    
    // 检查代理池状态
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
                
                // 添加连接信息（包括代理状态）
                $currentProxy = $fetcher->getCurrentProxy();
                if ($currentProxy) {
                    $responseData['connection_info'] = [
                        'proxy_detected' => true,
                        'proxy_used' => false, // IMAP扩展不支持代理
                        'proxy_type' => $currentProxy['proxy_type'],
                        'proxy_host' => $currentProxy['proxy_host'],
                        'proxy_port' => $currentProxy['proxy_port'],
                        'proxy_name' => $currentProxy['proxy_name'] ?? 'Unknown',
                        'connection_method' => 'direct',
                        'proxy_note' => 'PHP IMAP扩展不支持代理，已自动使用直连'
                    ];
                } else {
                    $responseData['connection_info'] = [
                        'proxy_detected' => false,
                        'proxy_used' => false,
                        'connection_method' => 'direct',
                        'note' => '使用直连方式'
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
                
                // 添加连接信息（包括代理状态）
                $currentProxy = $fetcher->getCurrentProxy();
                if ($currentProxy) {
                    $responseData['connection_info'] = [
                        'proxy_detected' => true,
                        'proxy_used' => false, // IMAP扩展不支持代理
                        'proxy_type' => $currentProxy['proxy_type'],
                        'proxy_host' => $currentProxy['proxy_host'],
                        'proxy_port' => $currentProxy['proxy_port'],
                        'proxy_name' => $currentProxy['proxy_name'] ?? 'Unknown',
                        'connection_method' => 'direct',
                        'proxy_note' => 'PHP IMAP扩展不支持代理，已自动使用直连'
                    ];
                } else {
                    $responseData['connection_info'] = [
                        'proxy_detected' => false,
                        'proxy_used' => false,
                        'connection_method' => 'direct',
                        'note' => '使用直连方式'
                    ];
                }
                
                echo json_encode($responseData);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'],
                'response_time' => $responseTime
            ]);
        }
    } else {
        // 连接失败
        $errorMessage = '无法连接到邮件服务器，请检查邮箱配置';
        
        // 添加代理相关错误信息
        if ($availableProxy) {
            $errorMessage .= '。注意：检测到可用代理但PHP IMAP扩展不支持代理连接，已使用直连尝试';
        } else {
            $errorMessage .= '。无可用代理，已使用直连方式尝试';
        }
        
        $errorData = [
            'success' => false,
            'message' => $errorMessage,
            'connection_info' => [
                'proxy_detected' => $availableProxy ? true : false,
                'proxy_used' => false,
                'connection_method' => 'direct',
                'imap_limitation' => 'PHP IMAP扩展不支持代理连接',
                'recommendation' => '请检查服务器地址、端口、用户名和密码。如需代理访问，请考虑其他方案'
            ]
        ];
        
        if ($availableProxy) {
            $errorData['connection_info']['proxy_details'] = [
                'type' => $availableProxy['proxy_type'],
                'host' => $availableProxy['proxy_host'],
                'port' => $availableProxy['proxy_port'],
                'note' => '代理已检测但无法用于IMAP连接'
            ];
        }
        
        echo json_encode($errorData);
    }
    
    $db->close();
    
} catch (Exception $e) {
    $errorMessage = '服务器错误: ' . $e->getMessage();
    
    // 检查是否是代理相关错误
    if (strpos($e->getMessage(), '代理') !== false || strpos($e->getMessage(), 'proxy') !== false) {
        $errorMessage .= '。提示：PHP IMAP扩展不支持代理连接，请检查网络配置或考虑替代方案';
    }
    
    echo json_encode([
        'success' => false,
        'message' => $errorMessage,
        'error_type' => 'server_error',
        'proxy_note' => 'PHP IMAP扩展不支持代理连接'
    ]);
}
?>