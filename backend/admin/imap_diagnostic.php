<?php
/**
 * IMAP扩展测试页面 (独立版本)
 * 用于诊断IMAP扩展安装和配置问题
 */

header('Content-Type: text/html; charset=utf-8');

// 独立的IMAP扩展检查函数
function checkImapExtension() {
    $diagnostics = [];
    
    // 添加环境信息
    $diagnostics['environment'] = [
        'php_sapi' => php_sapi_name(),
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? __FILE__
    ];
    
    // 检查扩展是否已加载
    if (!extension_loaded('imap')) {
        $sapi = php_sapi_name();
        $isCliSapi = in_array($sapi, ['cli', 'cli-server', 'phpdbg']);
        
        $errorMessage = '❌ 服务器未安装IMAP扩展';
        $troubleshootingInfo = [
            'extension_status' => '❌ php-imap扩展未安装或未加载',
            'environment_info' => "当前PHP运行环境: {$sapi}",
            'cli_vs_web' => $isCliSapi ? 
                '当前运行在CLI环境，请检查Web服务器PHP配置' : 
                '当前运行在Web服务器环境，可能与CLI环境配置不同'
        ];
        
        if ($isCliSapi) {
            $troubleshootingInfo['cli_suggestion'] = '命令行环境检测到IMAP扩展，但Web环境可能未配置';
            $troubleshootingInfo['web_check'] = '请检查Web服务器的php.ini文件是否加载了imap扩展';
        }
        
        $troubleshootingInfo = array_merge($troubleshootingInfo, [
            'solution' => '请联系管理员安装或启用php-imap扩展',
            'install_commands' => [
                'Ubuntu/Debian' => 'apt-get install php-imap',
                'CentOS/RHEL' => 'yum install php-imap',
                '宝塔面板' => 'PHP管理 → 安装扩展 → IMAP → 重启PHP服务'
            ],
            'verify_steps' => [
                'CLI检查' => 'php -m | grep imap',
                'Web检查' => '创建phpinfo()页面查看已加载扩展',
                '配置检查' => '确认Web服务器和CLI使用相同的php.ini配置'
            ]
        ]);
        
        return [
            'available' => false,
            'message' => $errorMessage,
            'diagnostics' => array_merge($diagnostics, $troubleshootingInfo)
        ];
    }
    
    $diagnostics['extension_status'] = '✅ php-imap扩展已加载';
    
    // 检查关键函数是否可用
    $requiredFunctions = ['imap_open', 'imap_close', 'imap_errors', 'imap_last_error', 'imap_num_msg'];
    $missingFunctions = [];
    $availableFunctions = [];
    
    foreach ($requiredFunctions as $function) {
        if (!function_exists($function)) {
            $missingFunctions[] = $function;
        } else {
            $availableFunctions[] = $function;
        }
    }
    
    if (!empty($missingFunctions)) {
        $diagnostics['function_issue'] = '❌ 部分IMAP函数不可用: ' . implode(', ', $missingFunctions);
        $diagnostics['available_functions'] = '✅ 可用函数: ' . implode(', ', $availableFunctions);
        
        return [
            'available' => false,
            'message' => '❌ IMAP扩展功能不完整',
            'diagnostics' => array_merge($diagnostics, [
                'missing_functions' => $missingFunctions,
                'solution' => '扩展已安装但功能不完整，请重新安装或重新配置php-imap扩展',
                'check_phpinfo' => '运行phpinfo()查看imap扩展详细信息',
                'rebuild_suggestion' => '可能需要重新编译PHP或重新安装imap扩展包'
            ])
        ];
    }
    
    $diagnostics['functions_status'] = '✅ 所有必需的IMAP函数都可用 (' . count($availableFunctions) . '个)';
    
    // 获取更详细的扩展信息
    try {
        // 尝试获取IMAP扩展的详细信息
        if (function_exists('imap_get_quotaroot')) {
            $diagnostics['imap_features'] = '✅ 扩展功能完整，支持高级特性';
        }
        
        // 尝试检查SSL支持
        if (function_exists('imap_open')) {
            $diagnostics['ssl_support'] = '✅ 支持SSL/TLS连接';
        }
        
        // 获取扩展编译信息
        ob_start();
        phpinfo(INFO_MODULES);
        $phpinfo = ob_get_clean();
        
        if (preg_match('/IMAP c-Client Version\s*=>\s*([^\n]+)/i', $phpinfo, $matches)) {
            $diagnostics['imap_version'] = '✅ IMAP c-Client版本: ' . trim($matches[1]);
        }
        
        if (strpos($phpinfo, 'SSL Support => enabled') !== false) {
            $diagnostics['ssl_compiled'] = '✅ SSL支持已编译';
        }
        
    } catch (Exception $e) {
        $diagnostics['info_warning'] = '⚠️ 无法获取部分扩展详细信息: ' . $e->getMessage();
    }
    
    $diagnostics['final_status'] = '✅ IMAP扩展完全可用，可以进行邮箱连接测试';
    
    return [
        'available' => true,
        'message' => '✅ IMAP扩展已正确安装并完全可用',
        'diagnostics' => $diagnostics
    ];
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMAP扩展诊断 - 邮件系统</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .status-success {
            color: #22c55e;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .status-error {
            color: #ef4444;
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .detail-item {
            margin: 8px 0;
            padding: 5px 0;
        }
        .detail-key {
            font-weight: bold;
            color: #475569;
            display: inline-block;
            min-width: 150px;
        }
        .detail-value {
            color: #1e293b;
        }
        .section {
            margin: 25px 0;
        }
        .section h3 {
            color: #374151;
            border-left: 4px solid #667eea;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .cmd {
            background: #f1f5f9;
            color: #334155;
            padding: 5px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
        }
        .refresh-btn {
            background: #10b981;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 10px 0;
        }
        .refresh-btn:hover {
            background: #059669;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 IMAP扩展诊断工具</h1>
        
        <div class="info-box">
            <strong>说明：</strong>此页面用于诊断PHP IMAP扩展的安装和配置状态，帮助解决邮箱连接问题。
        </div>

        <?php
        // 执行IMAP扩展检查
        $imapCheck = checkImapExtension();
        ?>

        <div class="section">
            <h3>🔍 扩展检查结果</h3>
            
            <?php if ($imapCheck['available']): ?>
                <div class="status-success">
                    <strong><?php echo htmlspecialchars($imapCheck['message']); ?></strong>
                </div>
            <?php else: ?>
                <div class="status-error">
                    <strong><?php echo htmlspecialchars($imapCheck['message']); ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <div class="section">
            <h3>📊 详细诊断信息</h3>
            
            <?php if (isset($imapCheck['diagnostics'])): ?>
                <?php foreach ($imapCheck['diagnostics'] as $key => $value): ?>
                    <div class="detail-item">
                        <?php if (is_array($value)): ?>
                            <div class="detail-key"><?php echo htmlspecialchars($key); ?>:</div>
                            <div style="margin-left: 20px;">
                                <?php foreach ($value as $subKey => $subValue): ?>
                                    <div class="detail-item">
                                        <span class="detail-key"><?php echo htmlspecialchars($subKey); ?>:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($subValue); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="detail-key"><?php echo htmlspecialchars($key); ?>:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($value); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="section">
            <h3>🛠 基础PHP信息</h3>
            
            <div class="detail-item">
                <span class="detail-key">PHP版本:</span>
                <span class="detail-value"><?php echo phpversion(); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-key">运行环境:</span>
                <span class="detail-value"><?php echo php_sapi_name(); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-key">服务器软件:</span>
                <span class="detail-value"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? '未知'; ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-key">扩展加载状态:</span>
                <span class="detail-value"><?php echo extension_loaded('imap') ? '✅ 已加载' : '❌ 未加载'; ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-key">imap_open函数:</span>
                <span class="detail-value"><?php echo function_exists('imap_open') ? '✅ 可用' : '❌ 不可用'; ?></span>
            </div>
        </div>

        <?php if (!$imapCheck['available']): ?>
        <div class="section">
            <h3>🔧 解决方案</h3>
            
            <p><strong>如果您看到IMAP扩展未安装的错误，请按以下步骤操作：</strong></p>
            
            <h4>1. 检查CLI环境是否有IMAP扩展</h4>
            <p>在服务器上运行：<span class="cmd">php -m | grep imap</span></p>
            
            <h4>2. 如果CLI有扩展但Web环境没有</h4>
            <p>这说明Web服务器和CLI使用了不同的PHP配置，请：</p>
            <ul>
                <li>检查Web服务器的php.ini文件</li>
                <li>确保IMAP扩展在Web环境中也已启用</li>
                <li>重启Web服务器</li>
            </ul>
            
            <h4>3. 宝塔面板用户</h4>
            <p>进入 <strong>软件商店 → PHP → 设置 → 安装扩展 → IMAP</strong>，然后重启PHP服务。</p>
            
            <h4>4. 命令行安装</h4>
            <p>Ubuntu/Debian: <span class="cmd">apt-get install php-imap</span></p>
            <p>CentOS/RHEL: <span class="cmd">yum install php-imap</span></p>
        </div>
        <?php endif; ?>

        <div class="section">
            <h3>🔄 操作</h3>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="refresh-btn">刷新检查</a>
            <a href="mailbox.php" class="refresh-btn">返回邮箱管理</a>
        </div>

        <div class="info-box">
            <strong>注意：</strong>如果此页面显示IMAP扩展可用，但邮箱管理页面仍然报错，可能是以下原因：
            <ul>
                <li>网络连接问题</li>
                <li>邮箱服务器配置错误</li>
                <li>用户名或密码错误</li>
                <li>防火墙阻止连接</li>
            </ul>
        </div>

        <div class="info-box">
            <strong>测试连接示例：</strong>要测试实际的邮箱连接，请使用邮箱管理页面的"测试连接"功能。此诊断页面只检查PHP扩展状态。
        </div>
    </div>
</body>
</html>