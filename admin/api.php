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
?>