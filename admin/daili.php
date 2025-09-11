<?php
session_start();

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ./');
    exit();
}

// 处理退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit();
}

require_once '../backend/utils/proxy_manager.php';

$message = '';
$error = '';

// 处理全局代理设置更新
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_global_proxy') {
    try {
        $proxyManager = new ProxyManager();
        $enabled = isset($_POST['global_proxy_enabled']) ? 1 : 0;
        $autoSelectFastest = isset($_POST['auto_select_fastest']) ? 1 : 0;
        
        if ($proxyManager->updateGlobalProxySettings($enabled, $autoSelectFastest)) {
            $message = '全局代理设置已更新';
        } else {
            $error = '更新设置失败';
        }
    } catch (Exception $e) {
        $error = '操作失败: ' . $e->getMessage();
    }
}

// 获取当前设置
try {
    $proxyManager = new ProxyManager();
    $settings = $proxyManager->getGlobalProxySettings();
    $fastestProxy = $proxyManager->getFastestProxy();
} catch (Exception $e) {
    $error = '获取设置失败: ' . $e->getMessage();
    $settings = ['global_proxy_enabled' => 0, 'auto_select_fastest' => 1];
    $fastestProxy = null;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理设置 - 邮件查看系统</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --sidebar-width: 280px;
            --header-height: 70px;
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            line-height: 1.6;
            color: #334155;
            transition: var(--transition);
        }
        
        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            z-index: 1000;
            transition: var(--transition);
            box-shadow: 4px 0 15px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-logo {
            color: white;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-item {
            display: block;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 15px 25px;
            transition: var(--transition);
            border-left: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.1);
            transform: translateX(-100%);
            transition: var(--transition);
        }
        
        .nav-item:hover::before {
            transform: translateX(0);
        }
        
        .nav-item:hover,
        .nav-item.active {
            color: white;
            background: rgba(255,255,255,0.15);
            border-left-color: white;
            transform: translateX(5px);
        }
        
        .nav-item i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: var(--transition);
        }
        
        .header {
            background: white;
            height: var(--header-height);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #64748b;
        }
        
        .logout-btn {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }
        
        /* Content Area */
        .content {
            padding: 30px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 25px;
            transition: var(--transition);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1), 0 4px 10px rgba(0,0,0,0.06);
        }
        
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #f1f5f9;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 500;
        }
        
        .form-group input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            border-left: 4px solid;
        }
        
        .message.success {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            color: #166534;
            border-left-color: #22c55e;
        }
        
        .message.error {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: #dc2626;
            border-left-color: #ef4444;
        }
        
        .proxy-info {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid var(--primary-color);
        }
        
        .proxy-info h4 {
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .proxy-info p {
            color: #64748b;
            margin: 4px 0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .header-title {
                font-size: 20px;
            }
            
            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">📧 邮件管理系统</div>
        </div>
        <nav class="sidebar-nav">
            <a href="home.php" class="nav-item">
                <i>🏠</i> 首页
            </a>
            <a href="mailbox.php" class="nav-item">
                <i>📫</i> 邮箱管理
            </a>
            <a href="daili.php" class="nav-item active">
                <i>🌐</i> 代理设置
            </a>
            <a href="kami.php" class="nav-item">
                <i>🔑</i> 卡密管理
            </a>
            <a href="kamirizhi.php" class="nav-item">
                <i>📝</i> 卡密日志
            </a>
            <a href="shoujian.php" class="nav-item">
                <i>📧</i> 收件日志
            </a>
            <a href="system.php" class="nav-item">
                <i>⚙️</i> 系统设置
            </a>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1 class="header-title">代理设置</h1>
            <div class="header-actions">
                <div class="user-info">
                    <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                </div>
                <a href="?logout=1" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="message success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <!-- 全局代理设置 -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">🌐 全局代理设置</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_global_proxy">
                        
                        <div class="form-group">
                            <label>
                                <span style="display: inline-block; width: 200px;">开启全局代理</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="global_proxy_enabled" <?php echo $settings['global_proxy_enabled'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </label>
                            <p style="color: #64748b; font-size: 14px; margin-top: 8px;">
                                启用后，系统将自动选择最快的代理进行邮件收取
                            </p>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="auto_select_fastest" <?php echo $settings['auto_select_fastest'] ? 'checked' : ''; ?>>
                                自动选择最快代理
                            </label>
                            <p style="color: #64748b; font-size: 14px; margin-top: 8px;">
                                系统将根据响应时间和成功率自动选择最优代理
                            </p>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            💾 保存设置
                        </button>
                    </form>
                    
                    <?php if ($settings['global_proxy_enabled'] && $fastestProxy): ?>
                        <div class="proxy-info">
                            <h4>当前最佳代理</h4>
                            <p><strong>名称:</strong> <?php echo htmlspecialchars($fastestProxy['proxy_name'] ?: '未命名'); ?></p>
                            <p><strong>类型:</strong> <?php echo strtoupper($fastestProxy['proxy_type']); ?></p>
                            <p><strong>地址:</strong> <?php echo htmlspecialchars($fastestProxy['proxy_host']); ?>:<?php echo $fastestProxy['proxy_port']; ?></p>
                            <p><strong>响应时间:</strong> <?php echo $fastestProxy['response_time']; ?>ms</p>
                            <p><strong>成功率:</strong> 
                                <?php 
                                $total = $fastestProxy['test_success_count'] + $fastestProxy['test_fail_count'];
                                if ($total > 0) {
                                    echo round(($fastestProxy['test_success_count'] / $total) * 100, 1) . '%';
                                } else {
                                    echo '未知';
                                }
                                ?>
                            </p>
                        </div>
                    <?php elseif ($settings['global_proxy_enabled'] && !$fastestProxy): ?>
                        <div class="proxy-info">
                            <h4>暂无可用代理</h4>
                            <p style="color: #dc2626;">请联系管理员添加代理服务器</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 说明卡片 -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📋 使用说明</h2>
                </div>
                <div class="card-body">
                    <ul style="color: #64748b; line-height: 1.8;">
                        <li><strong>全局代理:</strong> 启用后，所有邮件收取操作将通过代理服务器进行</li>
                        <li><strong>自动选择:</strong> 系统会根据代理的响应时间和成功率自动选择最优代理</li>
                        <li><strong>代理管理:</strong> 代理服务器由系统管理员统一配置和维护</li>
                        <li><strong>故障转移:</strong> 当前代理不可用时，系统会自动切换到其他可用代理</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>