<?php
/**
 * IMAP extension checking utility
 * Extracted from api.php to avoid session authentication
 */

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
    
    // 添加代理兼容性信息
    $diagnostics['proxy_limitation'] = '⚠️ PHP IMAP扩展不支持代理连接';
    $diagnostics['proxy_details'] = [
        'technical_reason' => 'imap_open()函数没有代理参数，底层C库不支持代理',
        'current_behavior' => '系统将检测代理配置但始终使用直连进行邮件操作',
        'workaround_needed' => '如需代理访问邮件服务器，请考虑替代方案'
    ];
    $diagnostics['proxy_alternatives'] = [
        'api_method' => '使用邮件服务商的API接口替代IMAP协议',
        'proxy_forwarding' => '在代理服务器上配置端口转发',
        'vpn_tunnel' => '使用VPN或SSH隧道建立网络连接',
        'mail_gateway' => '通过邮件网关服务转发请求'
    ];
    
    return [
        'available' => true,
        'message' => '✅ IMAP扩展已正确安装并完全可用',
        'diagnostics' => $diagnostics
    ];
}
?>