<?php
/**
 * IMAP扩展测试页面
 * 用于诊断IMAP扩展安装和配置问题
 */

header('Content-Type: text/html; charset=utf-8');

// 包含API文件以使用checkImapExtension函数
require_once 'api.php';

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
        pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            margin: 10px 0;
        }
        .cmd {
            background: #f1f5f9;
            color: #334155;
            padding: 5px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
        }
        .test-connection {
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin: 15px 0;
        }
        .test-connection:hover {
            background: #5b68d6;
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
    </div>
</body>
</html>