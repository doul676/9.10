<?php
/**
 * 邮件获取API
 * 为前端提供邮件获取服务
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
    $db_path = __DIR__ . '/../../db/mail.sqlite';
    
    // 检查数据库文件是否存在
    if (!file_exists($db_path)) {
        echo json_encode([
            'success' => false,
            'message' => '数据库文件不存在，请检查系统配置'
        ]);
        exit();
    }
    
    $db = new SQLite3($db_path);
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
    
    // 使用Python邮件服务
    $fetcher = new PythonMailFetcher($email);
    
    // 获取最新邮件
    $result = $fetcher->getLatestMail();
    
    if ($result['success']) {
        if (isset($result['mail'])) {
            echo json_encode([
                'success' => true,
                'message' => '邮件获取成功',
                'mail' => $result['mail'],
                'proxy' => $result['proxy'] ?? ['enabled' => false, 'info' => null]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => '邮箱中暂无邮件',
                'mail' => null,
                'proxy' => $result['proxy'] ?? ['enabled' => false, 'info' => null]
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
            'proxy' => $result['proxy'] ?? ['enabled' => false, 'info' => null]
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