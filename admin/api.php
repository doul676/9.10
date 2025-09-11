<?php
/**
 * 邮箱管理API
 * 提供邮箱测试连接等功能
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

require_once 'utils/mail_fetcher.php';
require_once '../backend/utils/imap_check.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'test_connection':
        testConnection();
        break;
    case 'get_servers':
        getServerAddresses();
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
 * 测试邮箱连接
 */
function testConnection() {
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
    
    // 检查是否通过account_id测试现有账号
    $accountId = $_POST['account_id'] ?? null;
    
    if ($accountId) {
        testExistingAccount($accountId);
        return;
    }
    
    // 从表单数据或JSON数据中获取参数
    $server = $input['server'] ?? $_POST['server'] ?? '';
    $port = (int)($input['port'] ?? $_POST['port'] ?? 0);
    $username = $input['username'] ?? $_POST['username'] ?? '';
    $password = $input['password'] ?? $_POST['password'] ?? '';
    $protocol = $input['protocol'] ?? $_POST['protocol'] ?? 'imap';
    $ssl = ($input['ssl'] ?? $_POST['ssl'] ?? false) ? true : false;
    
    // 验证必需参数
    if (empty($server) || empty($username) || empty($password) || $port <= 0) {
        echo json_encode([
            'success' => false,
            'message' => '请填写所有必需字段'
        ]);
        exit();
    }
    
    performConnectionTest($server, $port, $username, $password, $protocol, $ssl);
}

/**
 * 测试现有账号连接
 */
function testExistingAccount($accountId) {
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $stmt = $db->prepare('SELECT * FROM mail_accounts WHERE id = ?');
        $stmt->bindValue(1, $accountId);
        $result = $stmt->execute();
        $account = $result->fetchArray();
        
        if (!$account) {
            echo json_encode([
                'success' => false,
                'message' => '邮箱账号不存在'
            ]);
            $db->close();
            return;
        }
        
        performConnectionTest(
            $account['server'],
            $account['port'],
            $account['username'],
            $account['password'],
            $account['protocol'],
            $account['ssl'] == 1
        );
        
        $db->close();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '获取账号信息失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 执行连接测试
 */
function performConnectionTest($server, $port, $username, $password, $protocol, $ssl) {
    try {
        // 详细检查IMAP扩展状态
        $imapInfo = checkImapExtension();
        if (!$imapInfo['available']) {
            echo json_encode([
                'success' => false,
                'message' => $imapInfo['message'],
                'diagnostics' => $imapInfo['diagnostics'],
                'error_type' => 'extension_issue'
            ]);
            exit();
        }
        
        // 检查代理池状态
        require_once '../backend/utils/proxy_manager.php';
        $proxyManager = new ProxyManager();
        $availableProxy = $proxyManager->getAvailableProxy('', false); // 获取任何类型的可用代理
        
        // 创建邮件获取器实例
        $fetcher = new MailFetcher($server, $port, $username, $password, $protocol, $ssl);
        
        // 记录开始时间
        $startTime = microtime(true);
        
        // 尝试连接
        if ($fetcher->connect()) {
            $fetcher->close();
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            $responseData = [
                'success' => true,
                'message' => '✅ 邮箱连接测试成功！服务器响应正常',
                'response_time' => $responseTime,
                'diagnostics' => [
                    'imap_extension' => '✅ IMAP扩展已正确安装并可用',
                    'connection_protocol' => strtoupper($protocol) . ($ssl ? ' with SSL' : ' without SSL'),
                    'server_info' => $server . ':' . $port,
                    'response_time' => "响应时间: {$responseTime}ms"
                ]
            ];
            
            // 添加代理使用信息
            $currentProxy = $fetcher->getCurrentProxy();
            if ($currentProxy) {
                $responseData['diagnostics']['proxy_info'] = "✅ 通过代理连接: {$currentProxy['proxy_type']} {$currentProxy['proxy_host']}:{$currentProxy['proxy_port']}";
                $responseData['diagnostics']['proxy_name'] = $currentProxy['proxy_name'] ?? 'Unknown';
            } else {
                $responseData['diagnostics']['connection_type'] = $availableProxy ? 
                    '⚠️ 有可用代理但使用直连' : 
                    '📡 无可用代理，使用直连';
            }
            
            echo json_encode($responseData);
        } else {
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            $responseData = [
                'success' => false,
                'message' => '❌ 邮箱连接失败，请检查配置信息',
                'response_time' => $responseTime,
                'diagnostics' => [
                    'imap_extension' => '✅ IMAP扩展可用',
                    'connection_issue' => '服务器连接失败，请检查服务器地址、端口和凭据',
                    'response_time' => "尝试时间: {$responseTime}ms"
                ],
                'error_type' => 'connection_failed'
            ];
            
            // 添加代理信息
            if ($availableProxy) {
                $responseData['diagnostics']['proxy_status'] = '✅ 代理池有可用代理，已尝试通过代理连接';
            } else {
                $responseData['diagnostics']['proxy_status'] = '⚠️ 代理池无可用代理，已尝试直连';
            }
            
            echo json_encode($responseData);
        }
        
    } catch (Exception $e) {
        // 根据错误类型提供更具体的提示
        $errorMessage = $e->getMessage();
        $errorType = 'unknown';
        $diagnostics = ['imap_extension' => '✅ IMAP扩展可用'];
        
        // 检查代理使用情况
        try {
            require_once '../backend/utils/proxy_manager.php';
            $proxyManager = new ProxyManager();
            $availableProxy = $proxyManager->getAvailableProxy('', false);
            
            if ($availableProxy) {
                $diagnostics['proxy_status'] = '✅ 代理池有可用代理';
            } else {
                $diagnostics['proxy_status'] = '⚠️ 代理池无可用代理';
            }
        } catch (Exception $proxyError) {
            $diagnostics['proxy_status'] = '❌ 代理池检查失败: ' . $proxyError->getMessage();
        }
        
        if (strpos($errorMessage, 'SSL证书') !== false) {
            $errorType = 'ssl_error';
            $diagnostics['ssl_issue'] = '❌ SSL证书验证失败';
            $diagnostics['suggestion'] = '尝试关闭SSL选项或检查服务器SSL配置';
        } elseif (strpos($errorMessage, '连接被拒绝') !== false) {
            $errorType = 'connection_refused';
            $diagnostics['connection_issue'] = '❌ 服务器拒绝连接';
            $diagnostics['suggestion'] = '检查服务器地址和端口是否正确，防火墙是否允许连接';
        } elseif (strpos($errorMessage, '用户名或密码') !== false) {
            $errorType = 'auth_failed';
            $diagnostics['auth_issue'] = '❌ 身份验证失败';
            $diagnostics['suggestion'] = '检查邮箱地址和密码是否正确，某些邮箱需要应用专用密码';
        } elseif (strpos($errorMessage, '服务器地址') !== false) {
            $errorType = 'host_not_found';
            $diagnostics['dns_issue'] = '❌ 无法解析服务器地址';
            $diagnostics['suggestion'] = '检查服务器地址拼写是否正确，网络连接是否正常';
        } elseif (strpos($errorMessage, '代理') !== false) {
            $errorType = 'proxy_error';
            $diagnostics['proxy_issue'] = '❌ 代理连接问题';
            $diagnostics['suggestion'] = '检查代理服务器状态和配置';
        }
        
        echo json_encode([
            'success' => false,
            'message' => '❌ 连接测试失败：' . $errorMessage,
            'diagnostics' => $diagnostics,
            'error_type' => $errorType
        ]);
    }
}

/**
 * 获取服务器地址列表
 */
function getServerAddresses() {
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $result = $db->query('SELECT * FROM server_addresses ORDER BY server_name ASC');
        $servers = [];
        while ($row = $result->fetchArray()) {
            $servers[] = $row;
        }
        $db->close();
        
        echo json_encode([
            'success' => true,
            'servers' => $servers
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '获取服务器地址失败：' . $e->getMessage()
        ]);
    }
}
?>