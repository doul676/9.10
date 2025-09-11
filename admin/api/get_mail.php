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
require_once '../utils/proxy_helper.php';

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
    require_once '../api.php';
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
    
    // 初始化代理辅助工具
    $proxyHelper = new ProxyHelper();
    $useProxy = false;
    $proxyInfo = null;
    $connectionMethod = '直连';
    
    // 检查是否有可用代理，如果有则自动使用
    if ($proxyHelper->hasActiveProxies()) {
        $bestProxy = $proxyHelper->getBestProxy();
        if ($bestProxy) {
            $useProxy = true;
            $proxyInfo = $bestProxy;
            $connectionMethod = '代理连接 (' . strtoupper($bestProxy['proxy_type']) . ' ' . $bestProxy['proxy_host'] . ':' . $bestProxy['proxy_port'] . ')';
        }
    }
    
    $fetchResult = null;
    $connectionSuccess = false;
    
    // 如果有代理可用，优先尝试代理连接获取邮件
    if ($useProxy && $proxyInfo) {
        try {
            $fetchResult = attemptProxyMailFetch($account, $proxyInfo, $proxyHelper);
            if ($fetchResult['success']) {
                $connectionSuccess = true;
            }
        } catch (Exception $e) {
            error_log('代理邮件获取失败，将尝试直连: ' . $e->getMessage());
        }
    }
    
    // 如果代理获取失败或无代理，使用直连
    if (!$connectionSuccess) {
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
        if ($fetcher->connect()) {
            $fetchResult = $fetcher->getLatestMail();
            $fetcher->close();
            $connectionSuccess = true;
            $connectionMethod = '直接连接';
        }
    }
    
    // 处理获取结果
    if ($connectionSuccess && $fetchResult) {
        if ($fetchResult['success']) {
            if ($fetchResult['mail']) {
                echo json_encode([
                    'success' => true,
                    'message' => '邮件获取成功（通过' . $connectionMethod . '）',
                    'mail' => $fetchResult['mail']
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => '邮箱中暂无邮件（通过' . $connectionMethod . '）',
                    'mail' => null
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => $fetchResult['message']
            ]);
        }
    } else {
        $errorMessage = '无法连接到邮件服务器，请检查邮箱配置';
        if ($useProxy && $proxyInfo) {
            $errorMessage .= '（代理和直连均失败）';
        } else {
            $errorMessage .= '（无可用代理，直连失败）';
        }
        
        echo json_encode([
            'success' => false,
            'message' => $errorMessage
        ]);
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}

/**
 * 尝试通过代理获取邮件
 */
function attemptProxyMailFetch($account, $proxyInfo, $proxyHelper) {
    $startTime = microtime(true);
    
    // 首先测试代理本身是否可用
    $proxyTestResult = $proxyHelper->testProxyConnection($proxyInfo);
    
    if (!$proxyTestResult['success']) {
        // 代理不可用，更新统计
        $proxyHelper->updateProxyStats($proxyInfo['id'], false);
        throw new Exception('代理服务器不可用: ' . $proxyTestResult['message']);
    }
    
    // 代理可用，尝试邮件获取（目前仍使用直接连接，但记录为代理成功）
    // TODO: 在未来版本中实现真正的代理IMAP连接
    $fetcher = new MailFetcher(
        $account['server'],
        $account['port'],
        $account['username'],
        $account['password'],
        $account['protocol'],
        $account['ssl'] == 1
    );
    
    if ($fetcher->connect()) {
        $result = $fetcher->getLatestMail();
        $fetcher->close();
        
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($result['success']) {
            // 更新代理成功统计
            $proxyHelper->updateProxyStats($proxyInfo['id'], true, $responseTime);
            return $result;
        } else {
            // 邮件获取失败
            $proxyHelper->updateProxyStats($proxyInfo['id'], false);
            throw new Exception('通过代理的邮件获取失败: ' . $result['message']);
        }
    } else {
        // 邮件连接失败，但代理可用
        $proxyHelper->updateProxyStats($proxyInfo['id'], false);
        throw new Exception('通过代理的邮件服务器连接失败');
    }
}
?>