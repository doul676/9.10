<?php
/**
 * 服务器地址管理API
 * 提供服务器地址的增删改查功能
 */

session_start();

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未授权访问'
    ]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = new SQLite3('../../db/mail.sqlite');
    
    // 确保服务器地址表存在
    $db->exec('
        CREATE TABLE IF NOT EXISTS server_addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_name TEXT NOT NULL UNIQUE,
            server_address TEXT NOT NULL,
            default_port_imap INTEGER DEFAULT 993,
            default_port_pop3 INTEGER DEFAULT 995,
            default_ssl INTEGER DEFAULT 1,
            remarks TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // 初始化一些默认服务器地址
    initializeDefaultServers($db);
    
    switch ($action) {
        case 'list':
            listServerAddresses($db);
            break;
        case 'add':
            addServerAddress($db);
            break;
        case 'update':
            updateServerAddress($db);
            break;
        case 'delete':
            deleteServerAddress($db);
            break;
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => '无效的操作'
            ]);
            break;
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '操作失败：' . $e->getMessage()
    ]);
}

/**
 * 初始化默认服务器地址
 */
function initializeDefaultServers($db) {
    $defaultServers = [
        ['Gmail', 'imap.gmail.com', 993, 995, 1, 'Google Gmail 邮箱服务器'],
        ['Outlook/Hotmail', 'outlook.office365.com', 993, 995, 1, 'Microsoft Outlook/Hotmail 邮箱服务器'],
        ['Yahoo', 'imap.mail.yahoo.com', 993, 995, 1, 'Yahoo 邮箱服务器'],
        ['QQ邮箱', 'imap.qq.com', 993, 995, 1, '腾讯 QQ 邮箱服务器'],
        ['163邮箱', 'imap.163.com', 993, 995, 1, '网易 163 邮箱服务器'],
        ['126邮箱', 'imap.126.com', 993, 995, 1, '网易 126 邮箱服务器'],
        ['189邮箱', 'imap.189.cn', 993, 995, 1, '天翼 189 邮箱服务器'],
        ['企业微信邮箱', 'imap.exmail.qq.com', 993, 995, 1, '腾讯企业微信邮箱服务器']
    ];
    
    foreach ($defaultServers as $server) {
        try {
            $stmt = $db->prepare('INSERT OR IGNORE INTO server_addresses (server_name, server_address, default_port_imap, default_port_pop3, default_ssl, remarks) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bindValue(1, $server[0]);
            $stmt->bindValue(2, $server[1]);
            $stmt->bindValue(3, $server[2]);
            $stmt->bindValue(4, $server[3]);
            $stmt->bindValue(5, $server[4]);
            $stmt->bindValue(6, $server[5]);
            $stmt->execute();
        } catch (Exception $e) {
            // 忽略重复插入错误
        }
    }
}

/**
 * 获取服务器地址列表
 */
function listServerAddresses($db) {
    $result = $db->query('SELECT * FROM server_addresses ORDER BY created_at DESC');
    $servers = [];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $servers[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $servers
    ]);
}

/**
 * 添加服务器地址
 */
function addServerAddress($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $serverName = $input['server_name'] ?? $_POST['server_name'] ?? '';
    $serverAddress = $input['server_address'] ?? $_POST['server_address'] ?? '';
    $defaultPortImap = (int)($input['default_port_imap'] ?? $_POST['default_port_imap'] ?? 993);
    $defaultPortPop3 = (int)($input['default_port_pop3'] ?? $_POST['default_port_pop3'] ?? 995);
    $defaultSsl = isset($input['default_ssl']) ? ($input['default_ssl'] ? 1 : 0) : (isset($_POST['default_ssl']) ? 1 : 0);
    $remarks = $input['remarks'] ?? $_POST['remarks'] ?? '';
    
    if (empty($serverName) || empty($serverAddress)) {
        echo json_encode([
            'success' => false,
            'message' => '服务器名称和地址不能为空'
        ]);
        return;
    }
    
    // 检查名称是否已存在
    $stmt = $db->prepare('SELECT COUNT(*) FROM server_addresses WHERE server_name = ?');
    $stmt->bindValue(1, $serverName);
    $result = $stmt->execute();
    $count = $result->fetchArray()[0];
    
    if ($count > 0) {
        echo json_encode([
            'success' => false,
            'message' => '服务器名称已存在'
        ]);
        return;
    }
    
    // 使用北京时间
    $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    
    $stmt = $db->prepare('INSERT INTO server_addresses (server_name, server_address, default_port_imap, default_port_pop3, default_ssl, remarks, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bindValue(1, $serverName);
    $stmt->bindValue(2, $serverAddress);
    $stmt->bindValue(3, $defaultPortImap);
    $stmt->bindValue(4, $defaultPortPop3);
    $stmt->bindValue(5, $defaultSsl);
    $stmt->bindValue(6, $remarks);
    $stmt->bindValue(7, $beijingTime->format('Y-m-d H:i:s'));
    $stmt->bindValue(8, $beijingTime->format('Y-m-d H:i:s'));
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '服务器地址添加成功'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '添加失败'
        ]);
    }
}

/**
 * 更新服务器地址
 */
function updateServerAddress($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
    $serverName = $input['server_name'] ?? $_POST['server_name'] ?? '';
    $serverAddress = $input['server_address'] ?? $_POST['server_address'] ?? '';
    $defaultPortImap = (int)($input['default_port_imap'] ?? $_POST['default_port_imap'] ?? 993);
    $defaultPortPop3 = (int)($input['default_port_pop3'] ?? $_POST['default_port_pop3'] ?? 995);
    $defaultSsl = isset($input['default_ssl']) ? ($input['default_ssl'] ? 1 : 0) : (isset($_POST['default_ssl']) ? 1 : 0);
    $remarks = $input['remarks'] ?? $_POST['remarks'] ?? '';
    
    if ($id <= 0 || empty($serverName) || empty($serverAddress)) {
        echo json_encode([
            'success' => false,
            'message' => 'ID、服务器名称和地址不能为空'
        ]);
        return;
    }
    
    // 检查名称是否已被其他记录使用
    $stmt = $db->prepare('SELECT COUNT(*) FROM server_addresses WHERE server_name = ? AND id != ?');
    $stmt->bindValue(1, $serverName);
    $stmt->bindValue(2, $id);
    $result = $stmt->execute();
    $count = $result->fetchArray()[0];
    
    if ($count > 0) {
        echo json_encode([
            'success' => false,
            'message' => '服务器名称已被其他记录使用'
        ]);
        return;
    }
    
    // 使用北京时间
    $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    
    $stmt = $db->prepare('UPDATE server_addresses SET server_name=?, server_address=?, default_port_imap=?, default_port_pop3=?, default_ssl=?, remarks=?, updated_at=? WHERE id=?');
    $stmt->bindValue(1, $serverName);
    $stmt->bindValue(2, $serverAddress);
    $stmt->bindValue(3, $defaultPortImap);
    $stmt->bindValue(4, $defaultPortPop3);
    $stmt->bindValue(5, $defaultSsl);
    $stmt->bindValue(6, $remarks);
    $stmt->bindValue(7, $beijingTime->format('Y-m-d H:i:s'));
    $stmt->bindValue(8, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '服务器地址更新成功'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '更新失败'
        ]);
    }
}

/**
 * 删除服务器地址
 */
function deleteServerAddress($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID不能为空'
        ]);
        return;
    }
    
    $stmt = $db->prepare('DELETE FROM server_addresses WHERE id = ?');
    $stmt->bindValue(1, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '服务器地址删除成功'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '删除失败'
        ]);
    }
}
?>