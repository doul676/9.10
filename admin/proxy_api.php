<?php
/**
 * 代理池管理API
 * 提供代理池的增删改查和测试功能
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
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        listProxies();
        break;
    case 'add':
        addProxy();
        break;
    case 'update':
        updateProxy();
        break;
    case 'delete':
        deleteProxy();
        break;
    case 'toggle_status':
        toggleProxyStatus();
        break;
    case 'test_proxy':
        testProxy();
        break;
    case 'batch_delete':
        batchDeleteProxies();
        break;
    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '无效的操作'
        ]);
        break;
}

/**
 * 获取代理列表
 */
function listProxies() {
    try {
        $db = new SQLite3('../db/mail.sqlite');
        
        // 检查表是否存在，如果不存在则创建
        createProxyTableIfNotExists($db);
        
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 10);
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $type = $_GET['type'] ?? '';
        $status = $_GET['status'] ?? '';
        
        $whereConditions = [];
        $params = [];
        
        if (!empty($search)) {
            $whereConditions[] = "(proxy_name LIKE ? OR proxy_host LIKE ? OR remarks LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($type)) {
            $whereConditions[] = "proxy_type = ?";
            $params[] = $type;
        }
        
        if ($status !== '') {
            $whereConditions[] = "is_active = ?";
            $params[] = (int)$status;
        }
        
        $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
        
        // 获取总数
        $countSql = "SELECT COUNT(*) as total FROM proxy_pool $whereClause";
        $countStmt = $db->prepare($countSql);
        if (!empty($params)) {
            foreach ($params as $index => $param) {
                $countStmt->bindValue($index + 1, $param);
            }
        }
        $countResult = $countStmt->execute();
        $total = $countResult->fetchArray(SQLITE3_ASSOC)['total'];
        
        // 获取数据
        $sql = "SELECT * FROM proxy_pool $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        
        $paramIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($paramIndex++, $param);
        }
        $stmt->bindValue($paramIndex++, $limit);
        $stmt->bindValue($paramIndex, $offset);
        
        $result = $stmt->execute();
        $proxies = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $proxies[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $proxies,
            'pagination' => [
                'current_page' => $page,
                'total' => $total,
                'per_page' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '获取代理列表失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 添加代理
 */
function addProxy() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允许']);
        return;
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        createProxyTableIfNotExists($db);
        
        $proxy_name = $_POST['proxy_name'] ?? '';
        $proxy_type = $_POST['proxy_type'] ?? '';
        $proxy_host = $_POST['proxy_host'] ?? '';
        $proxy_port = (int)($_POST['proxy_port'] ?? 0);
        $proxy_username = $_POST['proxy_username'] ?? '';
        $proxy_password = $_POST['proxy_password'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        
        // 验证必需字段
        if (empty($proxy_host) || $proxy_port <= 0 || !in_array($proxy_type, ['http', 'socks5'])) {
            echo json_encode([
                'success' => false,
                'message' => '请填写所有必需字段并确保代理类型正确'
            ]);
            return;
        }
        
        // 检查是否已存在相同的代理
        $checkStmt = $db->prepare('SELECT id FROM proxy_pool WHERE proxy_host = ? AND proxy_port = ?');
        $checkStmt->bindValue(1, $proxy_host);
        $checkStmt->bindValue(2, $proxy_port);
        $checkResult = $checkStmt->execute();
        
        if ($checkResult->fetchArray()) {
            echo json_encode([
                'success' => false,
                'message' => '相同的代理地址和端口已存在'
            ]);
            return;
        }
        
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $timestamp = $beijingTime->format('Y-m-d H:i:s');
        
        $stmt = $db->prepare('INSERT INTO proxy_pool (proxy_name, proxy_type, proxy_host, proxy_port, proxy_username, proxy_password, remarks, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bindValue(1, $proxy_name);
        $stmt->bindValue(2, $proxy_type);
        $stmt->bindValue(3, $proxy_host);
        $stmt->bindValue(4, $proxy_port);
        $stmt->bindValue(5, $proxy_username);
        $stmt->bindValue(6, $proxy_password);
        $stmt->bindValue(7, $remarks);
        $stmt->bindValue(8, $timestamp);
        $stmt->bindValue(9, $timestamp);
        
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '代理添加成功'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '添加代理失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 更新代理
 */
function updateProxy() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允许']);
        return;
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        createProxyTableIfNotExists($db);
        
        $id = (int)($_POST['id'] ?? 0);
        $proxy_name = $_POST['proxy_name'] ?? '';
        $proxy_type = $_POST['proxy_type'] ?? '';
        $proxy_host = $_POST['proxy_host'] ?? '';
        $proxy_port = (int)($_POST['proxy_port'] ?? 0);
        $proxy_username = $_POST['proxy_username'] ?? '';
        $proxy_password = $_POST['proxy_password'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        
        if ($id <= 0 || empty($proxy_host) || $proxy_port <= 0 || !in_array($proxy_type, ['http', 'socks5'])) {
            echo json_encode([
                'success' => false,
                'message' => '请填写所有必需字段并确保代理类型正确'
            ]);
            return;
        }
        
        // 检查是否存在相同的代理（排除当前ID）
        $checkStmt = $db->prepare('SELECT id FROM proxy_pool WHERE proxy_host = ? AND proxy_port = ? AND id != ?');
        $checkStmt->bindValue(1, $proxy_host);
        $checkStmt->bindValue(2, $proxy_port);
        $checkStmt->bindValue(3, $id);
        $checkResult = $checkStmt->execute();
        
        if ($checkResult->fetchArray()) {
            echo json_encode([
                'success' => false,
                'message' => '相同的代理地址和端口已存在'
            ]);
            return;
        }
        
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $timestamp = $beijingTime->format('Y-m-d H:i:s');
        
        $stmt = $db->prepare('UPDATE proxy_pool SET proxy_name=?, proxy_type=?, proxy_host=?, proxy_port=?, proxy_username=?, proxy_password=?, remarks=?, updated_at=? WHERE id=?');
        $stmt->bindValue(1, $proxy_name);
        $stmt->bindValue(2, $proxy_type);
        $stmt->bindValue(3, $proxy_host);
        $stmt->bindValue(4, $proxy_port);
        $stmt->bindValue(5, $proxy_username);
        $stmt->bindValue(6, $proxy_password);
        $stmt->bindValue(7, $remarks);
        $stmt->bindValue(8, $timestamp);
        $stmt->bindValue(9, $id);
        
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '代理更新成功'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '更新代理失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 删除代理
 */
function deleteProxy() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允许']);
        return;
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode([
                'success' => false,
                'message' => '无效的代理ID'
            ]);
            return;
        }
        
        $stmt = $db->prepare('DELETE FROM proxy_pool WHERE id = ?');
        $stmt->bindValue(1, $id);
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '代理删除成功'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '删除代理失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 切换代理状态
 */
function toggleProxyStatus() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允许']);
        return;
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $id = (int)($_POST['id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode([
                'success' => false,
                'message' => '无效的代理ID'
            ]);
            return;
        }
        
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $timestamp = $beijingTime->format('Y-m-d H:i:s');
        
        $stmt = $db->prepare('UPDATE proxy_pool SET is_active=?, updated_at=? WHERE id=?');
        $stmt->bindValue(1, $is_active);
        $stmt->bindValue(2, $timestamp);
        $stmt->bindValue(3, $id);
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => $is_active ? '代理已启用' : '代理已禁用'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '切换代理状态失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 测试代理连接
 */
function testProxy() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允许']);
        return;
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode([
                'success' => false,
                'message' => '无效的代理ID'
            ]);
            return;
        }
        
        // 获取代理信息
        $stmt = $db->prepare('SELECT * FROM proxy_pool WHERE id = ?');
        $stmt->bindValue(1, $id);
        $result = $stmt->execute();
        $proxy = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$proxy) {
            echo json_encode([
                'success' => false,
                'message' => '代理不存在'
            ]);
            return;
        }
        
        $startTime = microtime(true);
        $testResult = testProxyConnection($proxy);
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        // 更新测试结果
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $timestamp = $beijingTime->format('Y-m-d H:i:s');
        
        if ($testResult['success']) {
            $updateStmt = $db->prepare('UPDATE proxy_pool SET is_verified=1, last_test_time=?, test_success_count=test_success_count+1, response_time=?, updated_at=? WHERE id=?');
        } else {
            $updateStmt = $db->prepare('UPDATE proxy_pool SET is_verified=0, last_test_time=?, test_fail_count=test_fail_count+1, response_time=?, updated_at=? WHERE id=?');
        }
        
        $updateStmt->bindValue(1, $timestamp);
        $updateStmt->bindValue(2, $responseTime);
        $updateStmt->bindValue(3, $timestamp);
        $updateStmt->bindValue(4, $id);
        $updateStmt->execute();
        
        echo json_encode([
            'success' => $testResult['success'],
            'message' => $testResult['message'],
            'response_time' => $responseTime
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '测试代理失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 批量删除代理
 */
function batchDeleteProxies() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允许']);
        return;
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $ids = $_POST['ids'] ?? [];
        
        if (empty($ids) || !is_array($ids)) {
            echo json_encode([
                'success' => false,
                'message' => '请选择要删除的代理'
            ]);
            return;
        }
        
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $db->prepare("DELETE FROM proxy_pool WHERE id IN ($placeholders)");
        
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, (int)$id);
        }
        
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '批量删除成功'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '批量删除失败: ' . $e->getMessage()
        ]);
    }
}

/**
 * 创建代理表（如果不存在）
 */
function createProxyTableIfNotExists($db) {
    $sql = "CREATE TABLE IF NOT EXISTS proxy_pool (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proxy_name TEXT NOT NULL DEFAULT '',
        proxy_type TEXT NOT NULL CHECK (proxy_type IN ('http', 'socks5')),
        proxy_host TEXT NOT NULL,
        proxy_port INTEGER NOT NULL,
        proxy_username TEXT DEFAULT '',
        proxy_password TEXT DEFAULT '',
        is_active INTEGER NOT NULL DEFAULT 1,
        is_verified INTEGER NOT NULL DEFAULT 0,
        last_test_time DATETIME DEFAULT NULL,
        test_success_count INTEGER DEFAULT 0,
        test_fail_count INTEGER DEFAULT 0,
        response_time INTEGER DEFAULT 0,
        remarks TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    
    $db->exec($sql);
    
    // 创建索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_proxy_pool_type ON proxy_pool(proxy_type)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_proxy_pool_active ON proxy_pool(is_active)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_proxy_pool_host_port ON proxy_pool(proxy_host, proxy_port)");
}

/**
 * 测试代理连接
 */
function testProxyConnection($proxy) {
    try {
        $testUrl = 'http://httpbin.org/ip';
        $timeout = 10;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // 设置代理
        if ($proxy['proxy_type'] === 'http') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        } elseif ($proxy['proxy_type'] === 'socks5') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
        
        curl_setopt($ch, CURLOPT_PROXY, $proxy['proxy_host'] . ':' . $proxy['proxy_port']);
        
        if (!empty($proxy['proxy_username']) && !empty($proxy['proxy_password'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['proxy_username'] . ':' . $proxy['proxy_password']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || !empty($error)) {
            return [
                'success' => false,
                'message' => '连接失败: ' . ($error ?: '未知错误')
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'message' => 'HTTP状态码错误: ' . $httpCode
            ];
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['origin'])) {
            return [
                'success' => false,
                'message' => '响应数据格式错误'
            ];
        }
        
        return [
            'success' => true,
            'message' => '连接成功，代理IP: ' . $data['origin']
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '测试失败: ' . $e->getMessage()
        ];
    }
}
?>