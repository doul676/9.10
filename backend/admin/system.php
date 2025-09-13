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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - 邮件查看系统</title>
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
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
            <a href="daili.php" class="nav-item">
                <i>🌐</i> 代理池
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
            <a href="system.php" class="nav-item active">
                <i>⚙️</i> 系统设置
            </a>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1 class="header-title">系统设置</h1>
            <div class="header-actions">
                <div class="user-info">
                    <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                </div>
                <a href="?logout=1" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <div class="content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">系统设置</h2>
                </div>
                <div class="card-body">
                    <p>系统设置功能正在开发中，敬请期待...</p>
                    <div style="margin-top: 20px;">
                        <h4>功能预览：</h4>
                        <ul style="margin-top: 10px; padding-left: 20px;">
                            <li>安全设置配置</li>
                            <li>邮件服务器设置</li>
                            <li>系统日志查看</li>
                            <li>数据备份与恢复</li>
                            <li>用户权限管理</li>
                            <li>系统监控配置</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>