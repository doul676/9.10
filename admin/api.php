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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'test_connection':
        testConnection();
        break;
    case 'get_servers':
        getServerAddresses();
        break;
    case 'add_server':
        addServerAddress();
        break;
    case 'update_server':
        updateServerAddress();
        break;
    case 'delete_server':
        deleteServerAddress();
        break;
    case 'batch_delete_servers':
        batchDeleteServerAddresses();
        break;
    case 'test_proxy':
        testProxyConnection();
        break;
    case 'refresh_proxies':
        refreshProxies();
        break;
    case 'toggle_proxy':
        toggleGlobalProxy();
        break;
    case 'get_proxy_status':
        getGlobalProxyStatus();
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
        
        // 创建邮件获取器实例
        $fetcher = new MailFetcher($server, $port, $username, $password, $protocol, $ssl);
        
        // 尝试连接
        if ($fetcher->connect()) {
            $fetcher->close();
            echo json_encode([
                'success' => true,
                'message' => '✅ 邮箱连接测试成功！服务器响应正常',
                'diagnostics' => [
                    'imap_extension' => '✅ IMAP扩展已正确安装并可用',
                    'connection_protocol' => strtoupper($protocol) . ($ssl ? ' with SSL' : ' without SSL'),
                    'server_info' => $server . ':' . $port
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => '❌ 邮箱连接失败，请检查配置信息',
                'diagnostics' => [
                    'imap_extension' => '✅ IMAP扩展可用',
                    'connection_issue' => '服务器连接失败，请检查服务器地址、端口和凭据'
                ],
                'error_type' => 'connection_failed'
            ]);
        }
        
    } catch (Exception $e) {
        // 根据错误类型提供更具体的提示
        $errorMessage = $e->getMessage();
        $errorType = 'unknown';
        $diagnostics = ['imap_extension' => '✅ IMAP扩展可用'];
        
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

/**
 * 添加服务器地址
 */
function addServerAddress() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => '只允许POST请求'
        ]);
        exit();
    }
    
    $serverName = $_POST['server_name'] ?? '';
    $serverAddress = $_POST['server_address'] ?? '';
    
    if (empty($serverName) || empty($serverAddress)) {
        echo json_encode([
            'success' => false,
            'message' => '请填写服务器名称和地址'
        ]);
        exit();
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $stmt = $db->prepare('INSERT INTO server_addresses (server_name, server_address, created_at, updated_at) VALUES (?, ?, ?, ?)');
        $stmt->bindValue(1, $serverName);
        $stmt->bindValue(2, $serverAddress);
        $stmt->bindValue(3, $beijingTime->format('Y-m-d H:i:s'));
        $stmt->bindValue(4, $beijingTime->format('Y-m-d H:i:s'));
        $stmt->execute();
        $db->close();
        
        echo json_encode([
            'success' => true,
            'message' => '服务器地址添加成功'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '添加失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 更新服务器地址
 */
function updateServerAddress() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => '只允许POST请求'
        ]);
        exit();
    }
    
    $id = (int)($_POST['server_id'] ?? 0);
    $serverName = $_POST['server_name'] ?? '';
    $serverAddress = $_POST['server_address'] ?? '';
    
    if ($id <= 0 || empty($serverName) || empty($serverAddress)) {
        echo json_encode([
            'success' => false,
            'message' => '请填写所有必需字段'
        ]);
        exit();
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $stmt = $db->prepare('UPDATE server_addresses SET server_name=?, server_address=?, updated_at=? WHERE id=?');
        $stmt->bindValue(1, $serverName);
        $stmt->bindValue(2, $serverAddress);
        $stmt->bindValue(3, $beijingTime->format('Y-m-d H:i:s'));
        $stmt->bindValue(4, $id);
        $stmt->execute();
        $db->close();
        
        echo json_encode([
            'success' => true,
            'message' => '服务器地址更新成功'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '更新失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 删除服务器地址
 */
function deleteServerAddress() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => '只允许POST请求'
        ]);
        exit();
    }
    
    $id = (int)($_POST['server_id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => '无效的服务器ID'
        ]);
        exit();
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $stmt = $db->prepare('DELETE FROM server_addresses WHERE id = ?');
        $stmt->bindValue(1, $id);
        $stmt->execute();
        $db->close();
        
        echo json_encode([
            'success' => true,
            'message' => '服务器地址删除成功'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '删除失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 批量删除服务器地址
 */
function batchDeleteServerAddresses() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => '只允许POST请求'
        ]);
        exit();
    }
    
    $ids = $_POST['server_ids'] ?? [];
    
    if (empty($ids) || !is_array($ids)) {
        echo json_encode([
            'success' => false,
            'message' => '请选择要删除的服务器地址'
        ]);
        exit();
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $db->prepare("DELETE FROM server_addresses WHERE id IN ($placeholders)");
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, (int)$id);
        }
        $stmt->execute();
        $db->close();
        
        echo json_encode([
            'success' => true,
            'message' => '成功删除 ' . count($ids) . ' 个服务器地址'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '批量删除失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 检查IMAP扩展状态并提供详细的诊断信息
 * 包括对CLI和Web服务器环境差异的检测
 * 确保所有IMAP核心函数可用才认为扩展功能完整
 */
function checkImapExtension() {
    $diagnostics = [];
    
    // 添加环境信息
    $sapi = php_sapi_name();
    $diagnostics['environment'] = [
        'php_sapi' => $sapi,
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? __FILE__
    ];
    
    // 检查扩展是否已加载
    if (!extension_loaded('imap')) {
        $isCliSapi = in_array($sapi, ['cli', 'cli-server', 'phpdbg']);
        
        $errorMessage = '❌ IMAP扩展功能不完整 - 扩展未加载';
        $troubleshootingInfo = [
            'extension_status' => '❌ php-imap扩展未安装或未加载',
            'environment_info' => "当前PHP运行环境: {$sapi}",
            'cli_vs_web' => $isCliSapi ? 
                '⚠️ 当前运行在CLI环境，请检查Web服务器PHP配置' : 
                '⚠️ 当前运行在Web服务器环境，可能与CLI环境配置不同',
            'common_cause' => '最常见原因是Web服务器和CLI使用不同的php.ini配置文件'
        ];
        
        if ($isCliSapi) {
            $troubleshootingInfo['cli_suggestion'] = '命令行环境可能配置了IMAP，但Web环境未配置';
            $troubleshootingInfo['web_check'] = '请检查Web服务器的php.ini文件是否加载了imap扩展';
        }
        
        $troubleshootingInfo = array_merge($troubleshootingInfo, [
            'solution_priority' => [
                '1. 宝塔面板用户' => 'PHP管理 → 安装扩展 → IMAP → 重启PHP服务',
                '2. Ubuntu/Debian' => 'sudo apt-get install php-imap && sudo systemctl restart apache2/nginx',
                '3. CentOS/RHEL' => 'sudo yum install php-imap && sudo systemctl restart httpd/nginx',
                '4. 手动编译' => '重新编译PHP时加入 --with-imap 选项'
            ],
            'verify_steps' => [
                'CLI检查' => 'php -m | grep imap',
                'Web检查' => '访问phpinfo()页面搜索"imap"',
                '配置检查' => 'php --ini 查看配置文件位置',
                '服务重启' => '重启Web服务器确保配置生效'
            ]
        ]);
        
        return [
            'available' => false,
            'message' => $errorMessage,
            'diagnostics' => array_merge($diagnostics, $troubleshootingInfo)
        ];
    }
    
    $diagnostics['extension_status'] = '✅ php-imap扩展已正确加载';
    
    // 检查所有必需的IMAP核心函数是否可用
    $requiredFunctions = [
        'imap_open' => '核心连接函数',
        'imap_close' => '连接关闭函数', 
        'imap_errors' => '错误获取函数',
        'imap_last_error' => '最后错误函数',
        'imap_num_msg' => '邮件计数函数',
        'imap_headerinfo' => '邮件头信息函数',
        'imap_fetchbody' => '邮件内容获取函数',
        'imap_fetchstructure' => '邮件结构函数',
        'imap_mime_header_decode' => '邮件头解码函数'
    ];
    
    $missingFunctions = [];
    $availableFunctions = [];
    
    foreach ($requiredFunctions as $function => $description) {
        if (!function_exists($function)) {
            $missingFunctions[$function] = $description;
        } else {
            $availableFunctions[$function] = $description;
        }
    }
    
    // 如果有任何核心函数缺失，认为扩展功能不完整
    if (!empty($missingFunctions)) {
        $missingList = [];
        foreach ($missingFunctions as $func => $desc) {
            $missingList[] = "{$func} ({$desc})";
        }
        
        $availableList = [];
        foreach ($availableFunctions as $func => $desc) {
            $availableList[] = "{$func} ({$desc})";
        }
        
        $diagnostics['function_issue'] = '❌ IMAP扩展功能不完整，部分核心函数不可用';
        $diagnostics['missing_functions'] = '缺失函数: ' . implode(', ', array_keys($missingFunctions));
        $diagnostics['available_functions'] = '可用函数: ' . implode(', ', array_keys($availableFunctions));
        $diagnostics['missing_details'] = $missingList;
        $diagnostics['available_details'] = $availableList;
        
        return [
            'available' => false,
            'message' => '❌ IMAP扩展功能不完整 - 核心函数缺失',
            'diagnostics' => array_merge($diagnostics, [
                'solution_priority' => [
                    '1. 重新安装扩展' => '完全卸载后重新安装php-imap扩展',
                    '2. 检查扩展版本' => '确保安装的是完整版本的imap扩展',
                    '3. 重新编译PHP' => '使用完整的imap库重新编译PHP',
                    '4. 检查依赖库' => '确保系统已安装libc-client-dev等依赖'
                ],
                'check_commands' => [
                    '检查扩展详情' => 'php -r "phpinfo();" | grep -A 20 imap',
                    '检查编译选项' => 'php -r "echo php_build();"',
                    '检查系统库' => 'dpkg -l | grep imap 或 rpm -qa | grep imap'
                ]
            ])
        ];
    }
    
    $diagnostics['functions_status'] = '✅ 所有IMAP核心函数完全可用 (' . count($availableFunctions) . '个)';
    $diagnostics['function_list'] = array_keys($availableFunctions);
    
    // 获取更详细的扩展信息用于高级诊断
    try {
        // 检查高级功能
        $advancedFunctions = ['imap_get_quotaroot', 'imap_setflag_full', 'imap_search'];
        $advancedAvailable = 0;
        foreach ($advancedFunctions as $func) {
            if (function_exists($func)) {
                $advancedAvailable++;
            }
        }
        
        if ($advancedAvailable === count($advancedFunctions)) {
            $diagnostics['imap_features'] = '✅ 扩展功能完整，支持所有高级特性';
        } else {
            $diagnostics['imap_features'] = "⚠️ 部分高级功能可用 ({$advancedAvailable}/" . count($advancedFunctions) . ")";
        }
        
        // 获取扩展编译信息
        ob_start();
        phpinfo(INFO_MODULES);
        $phpinfo = ob_get_clean();
        
        if (preg_match('/IMAP c-Client Version\s*=>\s*([^\n]+)/i', $phpinfo, $matches)) {
            $diagnostics['imap_version'] = '✅ IMAP c-Client版本: ' . trim($matches[1]);
        }
        
        if (strpos($phpinfo, 'SSL Support => enabled') !== false) {
            $diagnostics['ssl_compiled'] = '✅ SSL/TLS支持已编译并启用';
        } elseif (strpos($phpinfo, 'SSL Support') !== false) {
            $diagnostics['ssl_compiled'] = '⚠️ SSL支持状态不明确';
        } else {
            $diagnostics['ssl_compiled'] = '❌ 未找到SSL支持信息';
        }
        
        // 检查Kerberos支持
        if (strpos($phpinfo, 'Kerberos Support') !== false) {
            $diagnostics['kerberos_support'] = '✅ Kerberos认证支持可用';
        }
        
    } catch (Exception $e) {
        $diagnostics['info_warning'] = '⚠️ 无法获取扩展详细信息: ' . $e->getMessage();
    }
    
    $diagnostics['final_status'] = '✅ IMAP扩展完全可用，所有核心功能正常';
    $diagnostics['ready_for_connection'] = '✅ 已准备就绪，可以进行邮箱连接测试';
    
    return [
        'available' => true,
        'message' => '✅ IMAP扩展已正确安装并完全可用',
        'diagnostics' => $diagnostics
    ];
}

/**
 * 测试代理连接
 */
function testProxyConnection() {
    // 只允许POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => '只允许POST请求'
        ]);
        exit();
    }
    
    // 清理输出缓冲区，确保JSON响应纯净
    if (ob_get_level()) {
        ob_clean();
    }
    
    try {
        // 获取请求数据 - 支持JSON和表单两种格式
        $input = null;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode([
                    'success' => false,
                    'message' => 'JSON数据格式错误：' . json_last_error_msg()
                ]);
                exit();
            }
        }
        
        // 检查是否通过proxy_id测试现有代理
        $proxyId = $_POST['proxy_id'] ?? null;
        $proxyType = $_POST['proxy_type'] ?? $input['proxy_type'] ?? 'http';
        
        if ($proxyId) {
            testExistingProxy($proxyId, $proxyType);
            return;
        }
        
        // 从表单数据或JSON数据中获取参数
        $name = $input['name'] ?? $_POST['name'] ?? '';
        $host = $input['host'] ?? $_POST['host'] ?? '';
        $port = (int)($input['port'] ?? $_POST['port'] ?? 0);
        $username = $input['username'] ?? $_POST['username'] ?? '';
        $password = $input['password'] ?? $_POST['password'] ?? '';
        
        // 验证必需参数（名称可以为空，会自动生成）
        if (empty($host) || $port <= 0) {
            echo json_encode([
                'success' => false,
                'message' => '请填写代理地址和端口'
            ]);
            exit();
        }
        
        performProxyTest($name, $host, $port, $username, $password, $proxyType, null, true); // Add modal test flag
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '请求处理失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 测试现有代理连接
 */
function testExistingProxy($proxyId, $proxyType) {
    try {
        $db = new SQLite3('../db/mail.sqlite');
        
        // 验证代理类型
        $tableName = $proxyType === 'socks5' ? 'socks5_proxies' : 'http_proxies';
        
        $stmt = $db->prepare("SELECT * FROM {$tableName} WHERE id = ?");
        $stmt->bindValue(1, (int)$proxyId);
        $result = $stmt->execute();
        $proxy = $result->fetchArray(SQLITE3_ASSOC);
        
        $db->close();
        
        if (!$proxy) {
            echo json_encode([
                'success' => false,
                'message' => '代理不存在'
            ]);
            return;
        }
        
        // 确保代理名称不为空
        if (empty($proxy['name'])) {
            $proxy['name'] = "代理 {$proxy['host']}:{$proxy['port']}";
        }
        
        performProxyTest(
            $proxy['name'],
            $proxy['host'],
            $proxy['port'],
            $proxy['username'] ?? '',
            $proxy['password'] ?? '',
            $proxyType,
            $proxyId,  // 传递代理ID用于更新数据库
            false      // 现有代理测试，不是模态测试
        );
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '获取代理信息失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 执行代理测试
 */
function performProxyTest($name, $host, $port, $username, $password, $proxyType, $proxyId = null, $isModalTest = false) {
    try {
        $startTime = microtime(true);
        
        // 为空名称生成默认名称用于显示
        if (empty($name)) {
            $name = "代理 {$host}:{$port}";
        }
        
        // 解析代理服务器IP地址
        $resolvedIp = gethostbyname($host);
        $hostInfo = ($resolvedIp !== $host) ? "{$host} ({$resolvedIp})" : $host;
        
        // 首先测试代理服务器本身的连接
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$socket) {
            // 只有在非模态测试时才更新数据库
            if (!$isModalTest) {
                updateProxyTestResult($name, $host, $port, $proxyType, false, 0, $proxyId);
            }
            
            echo json_encode([
                'success' => false,
                'message' => "❌ {$name} 连接失败\n服务器: {$hostInfo}:{$port}\n错误: {$errstr} (代码 {$errno})",
                'diagnostics' => [
                    'proxy_type' => strtoupper($proxyType),
                    'host_port' => $host . ':' . $port,
                    'resolved_ip' => $resolvedIp,
                    'error_code' => $errno,
                    'error_message' => $errstr,
                    'suggestion' => '检查代理服务器地址、端口是否正确，防火墙是否允许连接'
                ]
            ]);
            return;
        }
        
        fclose($socket);
        
        $connectionTime = microtime(true);
        $basicLatency = round(($connectionTime - $startTime) * 1000);
        
        // 进一步测试代理功能
        $proxyFunctional = testProxyFunctionality($host, $port, $username, $password, $proxyType);
        
        $endTime = microtime(true);
        $totalLatency = round(($endTime - $startTime) * 1000);
        
        if ($proxyFunctional['success']) {
            // 测试通过代理连接到外部网站
            $connectivityResults = testProxyConnectivity($host, $port, $username, $password, $proxyType);
            
            // 只有在非模态测试时才更新数据库
            if (!$isModalTest) {
                updateProxyTestResult($name, $host, $port, $proxyType, true, $totalLatency, $proxyId);
            }
            
            // 构建成功消息，包含IP和延迟信息
            $message = "✅ {$name} 测试成功\n";
            $message .= "服务器: {$hostInfo}:{$port}\n";
            $message .= "解析IP: {$resolvedIp}\n";
            $message .= "连接延迟: {$basicLatency}ms\n";
            $message .= "总响应时间: {$totalLatency}ms";
            
            // 添加外部连接测试结果
            if (!empty($connectivityResults)) {
                $message .= "\n\n外部连接测试:";
                foreach ($connectivityResults as $site => $result) {
                    if ($result['status'] === 'success') {
                        $message .= "\n✅ {$site}: {$result['ip']} ({$result['latency']})";
                    } else {
                        $message .= "\n❌ {$site}: 连接失败";
                    }
                }
            }
            
            $diagnostics = [
                'proxy_type' => strtoupper($proxyType),
                'host_port' => $host . ':' . $port,
                'resolved_ip' => $resolvedIp,
                'basic_latency' => $basicLatency . 'ms',
                'total_latency' => $totalLatency . 'ms',
                'status' => '代理服务器功能正常',
                'proxy_test' => $proxyFunctional['message'],
                'connectivity_results' => $connectivityResults
            ];
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'diagnostics' => $diagnostics
            ]);
        } else {
            // 只有在非模态测试时才更新数据库
            if (!$isModalTest) {
                updateProxyTestResult($name, $host, $port, $proxyType, false, $totalLatency, $proxyId);
            }
            
            $message = "❌ {$name} 功能测试失败\n";
            $message .= "服务器: {$hostInfo}:{$port}\n";
            $message .= "解析IP: {$resolvedIp}\n";
            $message .= "连接延迟: {$basicLatency}ms\n";
            $message .= "错误: {$proxyFunctional['message']}";
            
            echo json_encode([
                'success' => false,
                'message' => $message,
                'diagnostics' => [
                    'proxy_type' => strtoupper($proxyType),
                    'host_port' => $host . ':' . $port,
                    'resolved_ip' => $resolvedIp,
                    'basic_latency' => $basicLatency . 'ms',
                    'total_latency' => $totalLatency . 'ms',
                    'status' => '端口可达，但代理功能异常',
                    'proxy_test' => $proxyFunctional['message'],
                    'suggestion' => '检查是否为有效的代理服务器，或者代理配置是否正确'
                ]
            ]);
        }
        
    } catch (Exception $e) {
        // 只有在非模态测试时才更新数据库
        if (!$isModalTest) {
            updateProxyTestResult($name, $host, $port, $proxyType, false, 0, $proxyId);
        }
        
        echo json_encode([
            'success' => false,
            'message' => "❌ {$name} 连接测试失败\n错误: " . $e->getMessage(),
            'diagnostics' => [
                'proxy_type' => strtoupper($proxyType),
                'host_port' => $host . ':' . $port,
                'error_type' => 'connection_failed',
                'suggestion' => '检查代理服务器是否正常运行，网络连接是否正常'
            ]
        ]);
    }
}

/**
 * 测试代理功能性（而不仅仅是端口连通性）
 */
function testProxyFunctionality($host, $port, $username, $password, $proxyType) {
    try {
        if ($proxyType === 'http') {
            return testHttpProxyFunctionality($host, $port, $username, $password);
        } else {
            return testSocks5ProxyFunctionality($host, $port, $username, $password);
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '代理功能测试异常：' . $e->getMessage()
        ];
    }
}

/**
 * 测试HTTP代理功能
 */
function testHttpProxyFunctionality($host, $port, $username, $password) {
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$socket) {
        return [
            'success' => false,
            'message' => '无法建立socket连接'
        ];
    }
    
    // 构建HTTP CONNECT请求测试
    $testHost = 'httpbin.org';
    $testPort = 80;
    
    $request = "CONNECT {$testHost}:{$testPort} HTTP/1.1\r\n";
    $request .= "Host: {$testHost}:{$testPort}\r\n";
    
    // 如果有认证信息，添加认证头
    if (!empty($username) && !empty($password)) {
        $auth = base64_encode($username . ':' . $password);
        $request .= "Proxy-Authorization: Basic {$auth}\r\n";
    }
    
    $request .= "\r\n";
    
    fwrite($socket, $request);
    
    // 设置超时
    stream_set_timeout($socket, 3);
    
    // 读取响应
    $response = fread($socket, 1024);
    fclose($socket);
    
    // 检查HTTP代理响应
    if (strpos($response, 'HTTP/1') !== false) {
        if (strpos($response, '200') !== false) {
            return [
                'success' => true,
                'message' => 'HTTP代理功能正常'
            ];
        } else if (strpos($response, '407') !== false) {
            return [
                'success' => false,
                'message' => 'HTTP代理需要身份验证'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'HTTP代理返回错误响应：' . trim(substr($response, 0, 100))
            ];
        }
    } else {
        return [
            'success' => false,
            'message' => '不是有效的HTTP代理服务器'
        ];
    }
}

/**
 * 测试SOCKS5代理功能
 */
function testSocks5ProxyFunctionality($host, $port, $username, $password) {
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$socket) {
        return [
            'success' => false,
            'message' => '无法建立socket连接'
        ];
    }
    
    // 设置超时
    stream_set_timeout($socket, 3);
    
    // SOCKS5握手 - 无认证方法
    $request = "\x05\x01\x00";
    fwrite($socket, $request);
    
    $response = fread($socket, 2);
    if (strlen($response) < 2) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'SOCKS5握手失败：无响应'
        ];
    }
    
    if (ord($response[0]) !== 5) {
        fclose($socket);
        return [
            'success' => false,
            'message' => '不是有效的SOCKS5代理服务器'
        ];
    }
    
    $authMethod = ord($response[1]);
    if ($authMethod === 0) {
        // 无需认证，测试连接请求
        $testHost = 'httpbin.org';
        $testPort = 80;
        
        // 解析目标主机IP
        $targetIp = gethostbyname($testHost);
        if ($targetIp === $testHost) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'SOCKS5测试失败：无法解析测试域名'
            ];
        }
        
        // 连接请求
        $request = "\x05\x01\x00\x01" . inet_pton($targetIp) . pack('n', $testPort);
        fwrite($socket, $request);
        
        $response = fread($socket, 10);
        fclose($socket);
        
        if (strlen($response) >= 2 && ord($response[1]) === 0) {
            return [
                'success' => true,
                'message' => 'SOCKS5代理功能正常'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'SOCKS5连接测试失败'
            ];
        }
    } else if ($authMethod === 2) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'SOCKS5代理需要用户名密码认证'
        ];
    } else if ($authMethod === 255) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'SOCKS5代理拒绝连接'
        ];
    } else {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'SOCKS5代理返回未知认证方法'
        ];
    }
}

/**
 * 测试代理连接到外部网站
 */
function testProxyConnectivity($host, $port, $username, $password, $proxyType) {
    $testSites = [
        'baidu.com' => ['host' => 'www.baidu.com', 'port' => 80],
        'httpbin.org' => ['host' => 'httpbin.org', 'port' => 80]
    ];
    
    $results = [];
    
    foreach ($testSites as $siteName => $siteInfo) {
        try {
            $startTime = microtime(true);
            
            // 解析目标网站的IP地址
            $resolvedIp = gethostbyname($siteInfo['host']);
            if ($resolvedIp === $siteInfo['host']) {
                $results[$siteName] = [
                    'status' => 'failed',
                    'message' => "❌ 无法解析 {$siteName} 的IP地址",
                    'ip' => null,
                    'latency' => null
                ];
                continue;
            }
            
            // 通过代理测试连接
            $connected = testProxyToSite($host, $port, $username, $password, $proxyType, $siteInfo['host'], $siteInfo['port']);
            
            $endTime = microtime(true);
            $latency = round(($endTime - $startTime) * 1000);
            
            if ($connected) {
                $results[$siteName] = [
                    'status' => 'success',
                    'message' => "✅ {$siteName} 连接成功",
                    'ip' => $resolvedIp,
                    'latency' => $latency . 'ms'
                ];
            } else {
                $results[$siteName] = [
                    'status' => 'failed',
                    'message' => "❌ 通过代理无法访问 {$siteName}",
                    'ip' => $resolvedIp,
                    'latency' => $latency . 'ms'
                ];
            }
            
        } catch (Exception $e) {
            $results[$siteName] = [
                'status' => 'error',
                'message' => "❌ 测试 {$siteName} 时发生错误：" . $e->getMessage(),
                'ip' => null,
                'latency' => null
            ];
        }
        
        // 限制测试数量以避免超时，只测试前2个
        if (count($results) >= 2) {
            break;
        }
    }
    
    return $results;
}

/**
 * 通过代理测试连接到特定网站
 */
function testProxyToSite($proxyHost, $proxyPort, $username, $password, $proxyType, $targetHost, $targetPort) {
    try {
        if ($proxyType === 'http') {
            return testHttpProxyToSite($proxyHost, $proxyPort, $username, $password, $targetHost, $targetPort);
        } else {
            return testSocks5ProxyToSite($proxyHost, $proxyPort, $username, $password, $targetHost, $targetPort);
        }
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 通过HTTP代理测试连接
 */
function testHttpProxyToSite($proxyHost, $proxyPort, $username, $password, $targetHost, $targetPort) {
    $socket = @fsockopen($proxyHost, $proxyPort, $errno, $errstr, 5);
    if (!$socket) {
        return false;
    }
    
    // 构建HTTP CONNECT请求
    $request = "CONNECT {$targetHost}:{$targetPort} HTTP/1.1\r\n";
    $request .= "Host: {$targetHost}:{$targetPort}\r\n";
    
    // 如果有认证信息，添加认证头
    if (!empty($username) && !empty($password)) {
        $auth = base64_encode($username . ':' . $password);
        $request .= "Proxy-Authorization: Basic {$auth}\r\n";
    }
    
    $request .= "\r\n";
    
    fwrite($socket, $request);
    
    // 读取响应
    $response = fread($socket, 1024);
    fclose($socket);
    
    // 检查是否收到200连接成功响应
    return strpos($response, '200') !== false;
}

/**
 * 通过SOCKS5代理测试连接（简化版本）
 */
function testSocks5ProxyToSite($proxyHost, $proxyPort, $username, $password, $targetHost, $targetPort) {
    $socket = @fsockopen($proxyHost, $proxyPort, $errno, $errstr, 5);
    if (!$socket) {
        return false;
    }
    
    // SOCKS5握手 - 无认证方法
    $request = "\x05\x01\x00";
    fwrite($socket, $request);
    
    $response = fread($socket, 2);
    if (strlen($response) < 2 || ord($response[1]) !== 0) {
        fclose($socket);
        return false;
    }
    
    // 连接请求
    $targetIp = gethostbyname($targetHost);
    $request = "\x05\x01\x00\x01" . inet_pton($targetIp) . pack('n', $targetPort);
    fwrite($socket, $request);
    
    $response = fread($socket, 10);
    fclose($socket);
    
    // 检查连接是否成功
    return strlen($response) >= 2 && ord($response[1]) === 0;
}

/**
 * 更新代理测试结果
 */
function updateProxyTestResult($name, $host, $port, $proxyType, $success, $responseTime, $proxyId = null) {
    try {
        $db = new SQLite3('../db/mail.sqlite');
        $tableName = $proxyType === 'socks5' ? 'socks5_proxies' : 'http_proxies';
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        
        // 如果提供了proxyId，直接使用ID更新；否则通过host和port查找
        if ($proxyId) {
            $checkStmt = $db->prepare("SELECT id FROM {$tableName} WHERE id = ?");
            $checkStmt->bindValue(1, (int)$proxyId);
        } else {
            $checkStmt = $db->prepare("SELECT id FROM {$tableName} WHERE host=? AND port=?");
            $checkStmt->bindValue(1, $host);
            $checkStmt->bindValue(2, $port);
        }
        
        $checkResult = $checkStmt->execute();
        $exists = $checkResult->fetchArray();
        
        if ($exists) {
            // 代理存在，更新测试结果
            if ($success) {
                if ($proxyId) {
                    $stmt = $db->prepare("UPDATE {$tableName} SET status=1, last_check=?, response_time=?, success_count=success_count+1, updated_at=? WHERE id=?");
                    $stmt->bindValue(4, (int)$proxyId);
                } else {
                    $stmt = $db->prepare("UPDATE {$tableName} SET status=1, last_check=?, response_time=?, success_count=success_count+1, updated_at=? WHERE host=? AND port=?");
                    $stmt->bindValue(4, $host);
                    $stmt->bindValue(5, $port);
                }
            } else {
                if ($proxyId) {
                    $stmt = $db->prepare("UPDATE {$tableName} SET status=0, last_check=?, response_time=?, fail_count=fail_count+1, updated_at=? WHERE id=?");
                    $stmt->bindValue(4, (int)$proxyId);
                } else {
                    $stmt = $db->prepare("UPDATE {$tableName} SET status=0, last_check=?, response_time=?, fail_count=fail_count+1, updated_at=? WHERE host=? AND port=?");
                    $stmt->bindValue(4, $host);
                    $stmt->bindValue(5, $port);
                }
            }
            
            $stmt->bindValue(1, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(2, $responseTime);
            $stmt->bindValue(3, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->execute();
        }
        // 如果代理不存在于数据库中（如新添加时测试），不进行数据库更新
        
        $db->close();
    } catch (Exception $e) {
        error_log('Failed to update proxy test result: ' . $e->getMessage());
    }
}

/**
 * 刷新代理列表
 */
function refreshProxies() {
    try {
        $db = new SQLite3('../db/mail.sqlite');
        
        // 确保代理表存在
        $db->exec('CREATE TABLE IF NOT EXISTS http_proxies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            host TEXT NOT NULL,
            port INTEGER NOT NULL,
            username TEXT DEFAULT "",
            password TEXT DEFAULT "",
            status INTEGER DEFAULT 1,
            last_check DATETIME DEFAULT NULL,
            response_time INTEGER DEFAULT 0,
            success_count INTEGER DEFAULT 0,
            fail_count INTEGER DEFAULT 0,
            remarks TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        $db->exec('CREATE TABLE IF NOT EXISTS socks5_proxies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            host TEXT NOT NULL,
            port INTEGER NOT NULL,
            username TEXT DEFAULT "",
            password TEXT DEFAULT "",
            status INTEGER DEFAULT 1,
            last_check DATETIME DEFAULT NULL,
            response_time INTEGER DEFAULT 0,
            success_count INTEGER DEFAULT 0,
            fail_count INTEGER DEFAULT 0,
            remarks TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // 确保全局代理配置表存在
        $db->exec('CREATE TABLE IF NOT EXISTS proxy_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            config_key TEXT NOT NULL UNIQUE,
            config_value TEXT NOT NULL,
            description TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // 插入默认代理配置（如果不存在）
        $db->exec("INSERT OR IGNORE INTO proxy_config (config_key, config_value, description) VALUES 
            ('proxy_enabled', '0', '全局代理启用状态'),
            ('active_proxy_type', '', '当前激活的代理类型 (http/socks5)'),
            ('active_proxy_id', '0', '当前激活的代理ID'),
            ('proxy_timeout', '30', '代理连接超时时间（秒）')");
        
        $allProxies = [];
        
        // 获取HTTP代理并添加类型标识
        $result = $db->query('SELECT *, "http" as proxy_type FROM http_proxies ORDER BY created_at DESC');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $allProxies[] = $row;
        }
        
        // 获取SOCKS5代理并添加类型标识
        $result = $db->query('SELECT *, "socks5" as proxy_type FROM socks5_proxies ORDER BY created_at DESC');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $allProxies[] = $row;
        }
        
        // 按创建时间排序所有代理
        usort($allProxies, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // 获取全局代理状态
        $proxyStatus = [];
        $statusResult = $db->query("SELECT config_key, config_value FROM proxy_config WHERE config_key IN ('proxy_enabled', 'active_proxy_type', 'active_proxy_id')");
        while ($row = $statusResult->fetchArray(SQLITE3_ASSOC)) {
            $proxyStatus[$row['config_key']] = $row['config_value'];
        }
        
        $db->close();
        
        // 统计信息
        $httpCount = count(array_filter($allProxies, function($proxy) { return $proxy['proxy_type'] === 'http'; }));
        $socks5Count = count(array_filter($allProxies, function($proxy) { return $proxy['proxy_type'] === 'socks5'; }));
        
        echo json_encode([
            'success' => true,
            'proxies' => $allProxies,
            'counts' => [
                'total' => count($allProxies),
                'http' => $httpCount,
                'socks5' => $socks5Count
            ],
            'globalStatus' => $proxyStatus,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '刷新代理列表失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 切换全局代理状态
 */
function toggleGlobalProxy() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => '只允许POST请求'
        ]);
        exit();
    }
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        
        // 确保全局代理配置表存在
        $db->exec('CREATE TABLE IF NOT EXISTS proxy_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            config_key TEXT NOT NULL UNIQUE,
            config_value TEXT NOT NULL,
            description TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // 插入默认代理配置（如果不存在）
        $db->exec("INSERT OR IGNORE INTO proxy_config (config_key, config_value, description) VALUES 
            ('proxy_enabled', '0', '全局代理启用状态'),
            ('active_proxy_type', '', '当前激活的代理类型 (http/socks5)'),
            ('active_proxy_id', '0', '当前激活的代理ID'),
            ('proxy_timeout', '30', '代理连接超时时间（秒）')");
        
        // 获取当前状态
        $currentStatus = $db->query("SELECT config_value FROM proxy_config WHERE config_key = 'proxy_enabled'");
        $currentRow = $currentStatus->fetchArray(SQLITE3_ASSOC);
        $isEnabled = $currentRow ? (int)$currentRow['config_value'] : 0;
        
        $action = $_POST['action'] ?? 'toggle';
        $proxyType = $_POST['proxy_type'] ?? '';
        $proxyId = (int)($_POST['proxy_id'] ?? 0);
        
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        
        if ($action === 'enable' && !$isEnabled) {
            // 启用代理
            if (empty($proxyType) || $proxyId <= 0) {
                echo json_encode([
                    'success' => false,
                    'message' => '启用代理需要指定代理类型和ID'
                ]);
                $db->close();
                return;
            }
            
            // 验证代理是否存在且状态正常
            $tableName = $proxyType === 'socks5' ? 'socks5_proxies' : 'http_proxies';
            $checkProxy = $db->prepare("SELECT id, name, host, port, status FROM {$tableName} WHERE id = ?");
            $checkProxy->bindValue(1, $proxyId);
            $proxyResult = $checkProxy->execute();
            $proxy = $proxyResult->fetchArray(SQLITE3_ASSOC);
            
            if (!$proxy) {
                echo json_encode([
                    'success' => false,
                    'message' => '指定的代理不存在'
                ]);
                $db->close();
                return;
            }
            
            if (!$proxy['status']) {
                echo json_encode([
                    'success' => false,
                    'message' => '无法启用状态异常的代理，请先测试代理连接'
                ]);
                $db->close();
                return;
            }
            
            // 更新配置
            $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
            $stmt->bindValue(1, '1');
            $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(3, 'proxy_enabled');
            $stmt->execute();
            
            $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
            $stmt->bindValue(1, $proxyType);
            $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(3, 'active_proxy_type');
            $stmt->execute();
            
            $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
            $stmt->bindValue(1, (string)$proxyId);
            $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(3, 'active_proxy_id');
            $stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => "已启用 {$proxy['name']} ({$proxy['host']}:{$proxy['port']}) 代理",
                'enabled' => true,
                'activeProxy' => [
                    'type' => $proxyType,
                    'id' => $proxyId,
                    'name' => $proxy['name'],
                    'host' => $proxy['host'],
                    'port' => $proxy['port']
                ]
            ]);
            
        } else if ($action === 'disable' && $isEnabled) {
            // 禁用代理
            $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
            $stmt->bindValue(1, '0');
            $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(3, 'proxy_enabled');
            $stmt->execute();
            
            $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
            $stmt->bindValue(1, '');
            $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(3, 'active_proxy_type');
            $stmt->execute();
            
            $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
            $stmt->bindValue(1, '0');
            $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(3, 'active_proxy_id');
            $stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => '已禁用全局代理',
                'enabled' => false
            ]);
            
        } else {
            // 切换状态
            $newStatus = $isEnabled ? 0 : 1;
            
            if ($newStatus === 1) {
                // 要启用代理，需要指定代理
                if (empty($proxyType) || $proxyId <= 0) {
                    echo json_encode([
                        'success' => false,
                        'message' => '启用代理需要指定代理类型和ID'
                    ]);
                    $db->close();
                    return;
                }
            }
            
            $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
            $stmt->bindValue(1, (string)$newStatus);
            $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
            $stmt->bindValue(3, 'proxy_enabled');
            $stmt->execute();
            
            if ($newStatus === 1) {
                $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
                $stmt->bindValue(1, $proxyType);
                $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(3, 'active_proxy_type');
                $stmt->execute();
                
                $stmt = $db->prepare("UPDATE proxy_config SET config_value = ?, updated_at = ? WHERE config_key = ?");
                $stmt->bindValue(1, (string)$proxyId);
                $stmt->bindValue(2, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(3, 'active_proxy_id');
                $stmt->execute();
            }
            
            echo json_encode([
                'success' => true,
                'message' => $newStatus ? '代理已启用' : '代理已禁用',
                'enabled' => (bool)$newStatus
            ]);
        }
        
        $db->close();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '切换代理状态失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 获取全局代理状态
 */
function getGlobalProxyStatus() {
    try {
        $db = new SQLite3('../db/mail.sqlite');
        
        // 确保全局代理配置表存在
        $db->exec('CREATE TABLE IF NOT EXISTS proxy_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            config_key TEXT NOT NULL UNIQUE,
            config_value TEXT NOT NULL,
            description TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // 插入默认代理配置（如果不存在）
        $db->exec("INSERT OR IGNORE INTO proxy_config (config_key, config_value, description) VALUES 
            ('proxy_enabled', '0', '全局代理启用状态'),
            ('active_proxy_type', '', '当前激活的代理类型 (http/socks5)'),
            ('active_proxy_id', '0', '当前激活的代理ID'),
            ('proxy_timeout', '30', '代理连接超时时间（秒）')");
        
        // 获取代理配置
        $config = [];
        $result = $db->query("SELECT config_key, config_value FROM proxy_config");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $config[$row['config_key']] = $row['config_value'];
        }
        
        $activeProxy = null;
        
        // 如果代理已启用，获取活动代理详情
        if (isset($config['proxy_enabled']) && $config['proxy_enabled'] === '1') {
            $proxyType = $config['active_proxy_type'] ?? '';
            $proxyId = (int)($config['active_proxy_id'] ?? 0);
            
            if (!empty($proxyType) && $proxyId > 0) {
                $tableName = $proxyType === 'socks5' ? 'socks5_proxies' : 'http_proxies';
                $stmt = $db->prepare("SELECT id, name, host, port, status FROM {$tableName} WHERE id = ?");
                $stmt->bindValue(1, $proxyId);
                $proxyResult = $stmt->execute();
                $activeProxy = $proxyResult->fetchArray(SQLITE3_ASSOC);
                
                if ($activeProxy) {
                    $activeProxy['proxy_type'] = $proxyType;
                }
            }
        }
        
        $db->close();
        
        echo json_encode([
            'success' => true,
            'enabled' => isset($config['proxy_enabled']) && $config['proxy_enabled'] === '1',
            'config' => $config,
            'activeProxy' => $activeProxy
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '获取代理状态失败：' . $e->getMessage()
        ]);
    }
}