<?php
/**
 * 邮件连接测试API
 * 专门用于测试邮箱连接和代理配置
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../utils/python_mail_bridge.php';

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
            'message' => '邮箱账号不存在，请联系管理员添加',
            'error_type' => 'account_not_found'
        ]);
        $db->close();
        exit();
    }
    
    // 使用Python邮件服务进行连接测试
    $fetcher = new PythonMailFetcher($email);
    
    // 执行连接测试
    $result = $fetcher->testConnection();
    
    // 添加账号信息到响应中（不包含密码）
    $result['account_info'] = [
        'email' => $account['email'],
        'server' => $account['server'],
        'port' => $account['port'],
        'protocol' => $account['protocol'],
        'ssl' => (bool) $account['ssl'],
        'remarks' => $account['remarks']
    ];
    
    echo json_encode($result);
    
    $db->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage(),
        'error_type' => 'server_error'
    ]);
}
?>