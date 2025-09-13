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
    // require_once '../api.php';
    // $imapInfo = checkImapExtension();
    
    // 简化的IMAP扩展检查（无需身份验证）
    if (!extension_loaded('imap')) {
        echo json_encode([
            'success' => false,
            'message' => 'PHP IMAP扩展未加载，请联系管理员配置',
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
        $account['ssl'] == 1
    );
    
    // 连接并获取最新邮件
    if ($fetcher->connect()) {
        $result = $fetcher->getLatestMail();
        $proxyInfo = $fetcher->getProxyInfo();
        $fetcher->close();
        
        if ($result['success']) {
            if ($result['mail']) {
                echo json_encode([
                    'success' => true,
                    'message' => '邮件获取成功',
                    'mail' => $result['mail'],
                    'proxy' => $proxyInfo
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => '邮箱中暂无邮件',
                    'mail' => null,
                    'proxy' => $proxyInfo
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'],
                'proxy' => $proxyInfo
            ]);
        }
    } else {
        $proxyInfo = $fetcher->getProxyInfo();
        echo json_encode([
            'success' => false,
            'message' => '无法连接到邮件服务器，请检查邮箱配置',
            'proxy' => $proxyInfo
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