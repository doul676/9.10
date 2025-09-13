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
    case 'test_proxy':
        testProxyConnection();
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
        $db = new SQLite3(__DIR__ . '/../db/mail.sqlite');
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
        
        // 创建邮件获取器实例（会自动检查并使用代理）
        $fetcher = new MailFetcher($server, $port, $username, $password, $protocol, $ssl);
        
        // 获取代理信息用于显示
        $proxyInfo = $fetcher->getProxyInfo();
        
        // 尝试连接
        if ($fetcher->connect()) {
            $fetcher->close();
            
            $diagnostics = [
                'imap_extension' => '✅ IMAP扩展已正确安装并可用',
                'connection_protocol' => strtoupper($protocol) . ($ssl ? ' with SSL' : ' without SSL'),
                'server_info' => $server . ':' . $port
            ];
            
            // 添加代理状态信息到诊断中
            if ($proxyInfo['enabled'] && $proxyInfo['info']) {
                $proxy = $proxyInfo['info'];
                $diagnostics['proxy_status'] = "✅ 使用代理连接：{$proxy['name']} ({$proxy['type']}) - {$proxy['host']}:{$proxy['port']}";
            } else {
                $diagnostics['proxy_status'] = "🌐 直接连接（未使用代理）";
            }
            
            echo json_encode([
                'success' => true,
                'message' => '✅ 邮箱连接测试成功！服务器响应正常',
                'diagnostics' => $diagnostics,
                'proxy' => $proxyInfo
            ]);
        } else {
            $diagnostics = [
                'imap_extension' => '✅ IMAP扩展可用',
                'connection_issue' => '服务器连接失败，请检查服务器地址、端口和凭据'
            ];
            
            // 添加代理状态信息到诊断中
            if ($proxyInfo['enabled'] && $proxyInfo['info']) {
                $proxy = $proxyInfo['info'];
                $diagnostics['proxy_status'] = "⚠️ 使用代理连接：{$proxy['name']} ({$proxy['type']}) - {$proxy['host']}:{$proxy['port']}";
                $diagnostics['proxy_note'] = "连接失败可能与代理配置有关，建议检查代理设置";
            } else {
                $diagnostics['proxy_status'] = "🌐 直接连接（未使用代理）";
            }
            
            echo json_encode([
                'success' => false,
                'message' => '❌ 邮箱连接失败，请检查配置信息',
                'diagnostics' => $diagnostics,
                'proxy' => $proxyInfo,
                'error_type' => 'connection_failed'
            ]);
        }
        
    } catch (Exception $e) {
        // 创建邮件获取器以获取代理信息
        $fetcher = new MailFetcher($server, $port, $username, $password, $protocol, $ssl);
        $proxyInfo = $fetcher->getProxyInfo();
        
        // 根据错误类型提供更具体的提示
        $errorMessage = $e->getMessage();
        $errorType = 'unknown';
        $diagnostics = ['imap_extension' => '✅ IMAP扩展可用'];
        
        // 添加代理状态信息到诊断中
        if ($proxyInfo['enabled'] && $proxyInfo['info']) {
            $proxy = $proxyInfo['info'];
            $diagnostics['proxy_status'] = "⚠️ 使用代理连接：{$proxy['name']} ({$proxy['type']}) - {$proxy['host']}:{$proxy['port']}";
            $diagnostics['proxy_note'] = "连接失败可能与代理配置有关，建议检查代理设置";
        } else {
            $diagnostics['proxy_status'] = "🌐 直接连接（未使用代理）";
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
        }
        
        echo json_encode([
            'success' => false,
            'message' => '❌ 连接测试失败：' . $errorMessage,
            'diagnostics' => $diagnostics,
            'proxy' => $proxyInfo,
            'error_type' => $errorType
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
        
        performProxyTest($name, $host, $port, $username, $password, $proxyType);
        
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
        $db = new SQLite3(__DIR__ . '/../db/mail.sqlite');
        
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
            $proxyId  // 传递代理ID用于更新数据库
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
function performProxyTest($name, $host, $port, $username, $password, $proxyType, $proxyId = null) {
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
            // 更新数据库中的测试结果
            updateProxyTestResult($name, $host, $port, $proxyType, false, 0, $proxyId);
            
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
            
            // 更新数据库中的测试结果
            updateProxyTestResult($name, $host, $port, $proxyType, true, $totalLatency, $proxyId);
            
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
            // 端口通但代理功能异常
            updateProxyTestResult($name, $host, $port, $proxyType, false, $totalLatency, $proxyId);
            
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
        // 更新数据库中的测试结果
        updateProxyTestResult($name, $host, $port, $proxyType, false, 0, $proxyId);
        
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
    stream_set_timeout($socket, 5);
    
    // SOCKS5握手 - 检查是否需要认证
    $hasAuth = !empty($username) && !empty($password);
    if ($hasAuth) {
        // 支持无认证和用户名密码认证
        $request = "\x05\x02\x00\x02";
    } else {
        // 只支持无认证
        $request = "\x05\x01\x00";
    }
    
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
        // 无需认证，直接测试连接
        return testSocks5Connection($socket);
    } else if ($authMethod === 2 && $hasAuth) {
        // 需要用户名密码认证
        $userLen = strlen($username);
        $passLen = strlen($password);
        $authRequest = "\x01" . chr($userLen) . $username . chr($passLen) . $password;
        fwrite($socket, $authRequest);
        
        $authResponse = fread($socket, 2);
        if (strlen($authResponse) < 2) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'SOCKS5用户认证失败：无响应'
            ];
        }
        
        if (ord($authResponse[1]) === 0) {
            // 认证成功，测试连接
            return testSocks5Connection($socket);
        } else {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'SOCKS5用户认证失败：用户名或密码错误'
            ];
        }
    } else if ($authMethod === 2 && !$hasAuth) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'SOCKS5代理需要用户名密码认证，但未提供认证信息'
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
            'message' => 'SOCKS5代理返回未知认证方法: ' . $authMethod
        ];
    }
}

/**
 * 测试SOCKS5连接到目标服务器
 */
function testSocks5Connection($socket) {
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
    
    // 连接请求 - IPv4
    $request = "\x05\x01\x00\x01" . inet_pton($targetIp) . pack('n', $testPort);
    fwrite($socket, $request);
    
    $response = fread($socket, 10);
    fclose($socket);
    
    if (strlen($response) >= 2) {
        $replyCode = ord($response[1]);
        if ($replyCode === 0) {
            return [
                'success' => true,
                'message' => 'SOCKS5代理功能正常'
            ];
        } else {
            $errorMessages = [
                1 => '一般SOCKS服务器失败',
                2 => '连接规则不允许',
                3 => '网络不可达',
                4 => '主机不可达',
                5 => '连接被拒绝',
                6 => 'TTL超时',
                7 => '命令不支持',
                8 => '地址类型不支持'
            ];
            $errorMsg = $errorMessages[$replyCode] ?? "未知错误码: $replyCode";
            return [
                'success' => false,
                'message' => "SOCKS5连接测试失败：$errorMsg"
            ];
        }
    } else {
        return [
            'success' => false,
            'message' => 'SOCKS5连接测试失败：响应不完整'
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
        $db = new SQLite3(__DIR__ . '/../db/mail.sqlite');
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