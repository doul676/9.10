<?php
/**
 * 邮件获取API
 * 为前端提供邮件获取服务，优先使用代理支持的webklex/php-imap库
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

// 优先使用支持代理的mail fetcher，如果失败则回退到原版
require_once '../utils/mail_fetcher_proxy.php';
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
    
    // 优先使用支持代理的邮件获取器
    $useProxyFetcher = true;
    $fetcher = null;
    
    try {
        // 尝试使用webklex/php-imap的代理支持版本
        $fetcher = new MailFetcherProxy(
            $account['server'],
            $account['port'],
            $account['username'],
            $account['password'],
            $account['protocol'],
            $account['ssl'] == 1
        );
    } catch (Exception $e) {
        // 如果新版本失败，回退到原版
        error_log('代理邮件获取器创建失败，回退到原版: ' . $e->getMessage());
        $useProxyFetcher = false;
        $fetcher = new MailFetcher(
            $account['server'],
            $account['port'],
            $account['username'],
            $account['password'],
            $account['protocol'],
            $account['ssl'] == 1
        );
    }
    
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
                
                // 添加代理使用信息
                $currentProxy = $fetcher->getCurrentProxy();
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
                        'message' => $availableProxy ? '代理可用但未使用' : '无可用代理，使用直连'
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
                $currentProxy = $fetcher->getCurrentProxy();
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
                        'message' => $availableProxy ? '代理可用但未使用' : '无可用代理，使用直连'
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
        echo json_encode([
            'success' => false,
            'message' => '无法连接到邮件服务器，请检查邮箱配置。' . 
                        ($availableProxy ? ' 已尝试通过代理连接。' : ' 无可用代理，已尝试直连。')
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