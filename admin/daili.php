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

$message = '';
$error = '';

// 处理代理操作
if ($_POST) {
    $action = $_POST['action'] ?? '';
    $proxyType = $_POST['proxy_type'] ?? 'http';
    
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
        
        $tableName = $proxyType === 'socks5' ? 'socks5_proxies' : 'http_proxies';
        
        if ($action === 'add') {
            $name = $_POST['name'] ?? '';
            $host = $_POST['host'] ?? '';
            $port = (int)($_POST['port'] ?? 0);
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $remarks = $_POST['remarks'] ?? '';
            
            if ($name && $host && $port) {
                $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
                $stmt = $db->prepare("INSERT INTO {$tableName} (name, host, port, username, password, remarks, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $host);
                $stmt->bindValue(3, $port);
                $stmt->bindValue(4, $username);
                $stmt->bindValue(5, $password);
                $stmt->bindValue(6, $remarks);
                $stmt->bindValue(7, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(8, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->execute();
                $message = strtoupper($proxyType) . '代理添加成功';
            } else {
                $error = '请填写所有必需字段';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM {$tableName} WHERE id = ?");
                $stmt->bindValue(1, $id);
                $stmt->execute();
                $message = strtoupper($proxyType) . '代理删除成功';
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $host = $_POST['host'] ?? '';
            $port = (int)($_POST['port'] ?? 0);
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $remarks = $_POST['remarks'] ?? '';
            
            if ($id > 0 && $name && $host && $port) {
                $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
                $stmt = $db->prepare("UPDATE {$tableName} SET name=?, host=?, port=?, username=?, password=?, remarks=?, updated_at=? WHERE id=?");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $host);
                $stmt->bindValue(3, $port);
                $stmt->bindValue(4, $username);
                $stmt->bindValue(5, $password);
                $stmt->bindValue(6, $remarks);
                $stmt->bindValue(7, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(8, $id);
                $stmt->execute();
                $message = strtoupper($proxyType) . '代理更新成功';
            } else {
                $error = '请填写所有必需字段';
            }
        } elseif ($action === 'batch_delete') {
            $ids = $_POST['ids'] ?? [];
            if (!empty($ids) && is_array($ids)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM {$tableName} WHERE id IN ($placeholders)");
                foreach ($ids as $index => $id) {
                    $stmt->bindValue($index + 1, (int)$id);
                }
                $stmt->execute();
                $message = '成功删除 ' . count($ids) . ' 个' . strtoupper($proxyType) . '代理';
            } else {
                $error = '请选择要删除的代理';
            }
        }
        
        $db->close();
    } catch (Exception $e) {
        $error = '操作失败：' . $e->getMessage();
    }
}

// 获取代理列表
$httpProxies = [];
$socks5Proxies = [];
try {
    $db = new SQLite3('../db/mail.sqlite');
    
    // 确保表存在
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
    
    // 获取HTTP代理
    $result = $db->query('SELECT * FROM http_proxies ORDER BY created_at DESC');
    while ($row = $result->fetchArray()) {
        $httpProxies[] = $row;
    }
    
    // 获取SOCKS5代理
    $result = $db->query('SELECT * FROM socks5_proxies ORDER BY created_at DESC');
    while ($row = $result->fetchArray()) {
        $socks5Proxies[] = $row;
    }
    
    $db->close();
} catch (Exception $e) {
    $error = '获取代理列表失败：' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理池 - 邮件查看系统</title>
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
        
        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 500px;
        }
        
        .toast {
            padding: 15px 20px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transform: translateX(550px);
            opacity: 0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        
        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .toast.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .toast.error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .toast.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        
        .toast::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255,255,255,0.8);
            animation: toastProgress 3s linear;
        }
        
        @keyframes toastProgress {
            from { width: 100%; }
            to { width: 0%; }
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
        }
        
        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #64748b;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }
        
        .modal-close:hover {
            background: #f1f5f9;
            color: #374151;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
            background: white;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Buttons */
        .btn {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .btn-danger:hover {
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .btn-success:hover {
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .table th,
        .table td {
            text-align: left;
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .table th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        /* Actions */
        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        /* Messages */
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
        
        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #f1f5f9;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 15px 25px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: var(--transition);
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 500;
            color: #64748b;
        }
        
        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.05);
        }
        
        .tab:hover:not(.active) {
            color: #374151;
            background: #f8fafc;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Status indicators */
        .status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status.active {
            background: #dcfce7;
            color: #166534;
        }
        
        .status.inactive {
            background: #fee2e2;
            color: #dc2626;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translateY(-20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
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
            <a href="system.php" class="nav-item">
                <i>⚙️</i> 系统设置
            </a>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1 class="header-title">代理池</h1>
            <div class="header-actions">
                <div class="user-info">
                    <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                </div>
                <a href="?logout=1" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <div class="content">
            <!-- Toast notification container -->
            <div id="toast-container" class="toast-container"></div>
            
            <!-- Tabs for proxy types -->
            <div class="card">
                <div class="card-body" style="padding: 0;">
                    <div class="tabs">
                        <button class="tab active" onclick="switchTab('http')">HTTP代理</button>
                        <button class="tab" onclick="switchTab('socks5')">SOCKS5代理</button>
                    </div>
                </div>
            </div>
            
            <!-- HTTP Proxy Tab -->
            <div id="http-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">HTTP代理管理</h2>
                    </div>
                    <div class="card-body">
                        <div class="button-group" style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <button type="button" class="btn" onclick="openAddModal('http')">
                                ➕ 添加HTTP代理
                            </button>
                            <button type="button" class="btn btn-success" onclick="testAllProxies('http')">
                                🔍 测试所有HTTP代理
                            </button>
                        </div>
                        <p style="margin-top: 15px; color: #64748b;">管理HTTP代理服务器配置</p>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">HTTP代理列表 (共 <?php echo count($httpProxies); ?> 个)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($httpProxies)): ?>
                            <p>暂无HTTP代理，请添加第一个HTTP代理。</p>
                        <?php else: ?>
                            <div class="table-container">
                                <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                                    <button type="button" class="btn btn-danger" onclick="batchDelete('http')" id="batchDeleteHttpBtn" style="display: none;">
                                        🗑️ 批量删除选中
                                    </button>
                                    <span id="selectedHttpCount" style="color: #64748b; font-size: 14px;"></span>
                                </div>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">
                                                <input type="checkbox" id="selectAllHttp" onchange="toggleSelectAll('http')">
                                            </th>
                                            <th>序号</th>
                                            <th>代理名称</th>
                                            <th>地址:端口</th>
                                            <th>用户名</th>
                                            <th>状态</th>
                                            <th>响应时间</th>
                                            <th>成功/失败</th>
                                            <th>备注</th>
                                            <th>添加时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $index = 1;
                                        foreach ($httpProxies as $proxy): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="http-checkbox" value="<?php echo $proxy['id']; ?>" onchange="updateBatchDeleteButton('http')">
                                                </td>
                                                <td><?php echo $index++; ?></td>
                                                <td><?php echo htmlspecialchars($proxy['name']); ?></td>
                                                <td><?php echo htmlspecialchars($proxy['host']) . ':' . $proxy['port']; ?></td>
                                                <td><?php echo htmlspecialchars($proxy['username'] ?: '无'); ?></td>
                                                <td>
                                                    <span class="status <?php echo $proxy['status'] ? 'active' : 'inactive'; ?>">
                                                        <?php echo $proxy['status'] ? '正常' : '异常'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $proxy['response_time'] ? $proxy['response_time'] . 'ms' : '-'; ?></td>
                                                <td><?php echo $proxy['success_count'] . '/' . $proxy['fail_count']; ?></td>
                                                <td><?php echo htmlspecialchars($proxy['remarks'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($proxy['created_at']); ?></td>
                                                <td>
                                                    <div class="actions">
                                                        <button type="button" class="btn btn-small" onclick="openEditModal('http', <?php echo htmlspecialchars(json_encode($proxy)); ?>)">编辑</button>
                                                        <button type="button" class="btn btn-small btn-success" onclick="testProxy('http', <?php echo $proxy['id']; ?>)" id="test-btn-http-<?php echo $proxy['id']; ?>">测试</button>
                                                        <button type="button" class="btn btn-danger btn-small" onclick="deleteProxy('http', <?php echo $proxy['id']; ?>)">删除</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- SOCKS5 Proxy Tab -->
            <div id="socks5-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">SOCKS5代理管理</h2>
                    </div>
                    <div class="card-body">
                        <div class="button-group" style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <button type="button" class="btn" onclick="openAddModal('socks5')">
                                ➕ 添加SOCKS5代理
                            </button>
                            <button type="button" class="btn btn-success" onclick="testAllProxies('socks5')">
                                🔍 测试所有SOCKS5代理
                            </button>
                        </div>
                        <p style="margin-top: 15px; color: #64748b;">管理SOCKS5代理服务器配置</p>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">SOCKS5代理列表 (共 <?php echo count($socks5Proxies); ?> 个)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($socks5Proxies)): ?>
                            <p>暂无SOCKS5代理，请添加第一个SOCKS5代理。</p>
                        <?php else: ?>
                            <div class="table-container">
                                <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                                    <button type="button" class="btn btn-danger" onclick="batchDelete('socks5')" id="batchDeleteSocks5Btn" style="display: none;">
                                        🗑️ 批量删除选中
                                    </button>
                                    <span id="selectedSocks5Count" style="color: #64748b; font-size: 14px;"></span>
                                </div>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">
                                                <input type="checkbox" id="selectAllSocks5" onchange="toggleSelectAll('socks5')">
                                            </th>
                                            <th>序号</th>
                                            <th>代理名称</th>
                                            <th>地址:端口</th>
                                            <th>用户名</th>
                                            <th>状态</th>
                                            <th>响应时间</th>
                                            <th>成功/失败</th>
                                            <th>备注</th>
                                            <th>添加时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $index = 1;
                                        foreach ($socks5Proxies as $proxy): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="socks5-checkbox" value="<?php echo $proxy['id']; ?>" onchange="updateBatchDeleteButton('socks5')">
                                                </td>
                                                <td><?php echo $index++; ?></td>
                                                <td><?php echo htmlspecialchars($proxy['name']); ?></td>
                                                <td><?php echo htmlspecialchars($proxy['host']) . ':' . $proxy['port']; ?></td>
                                                <td><?php echo htmlspecialchars($proxy['username'] ?: '无'); ?></td>
                                                <td>
                                                    <span class="status <?php echo $proxy['status'] ? 'active' : 'inactive'; ?>">
                                                        <?php echo $proxy['status'] ? '正常' : '异常'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $proxy['response_time'] ? $proxy['response_time'] . 'ms' : '-'; ?></td>
                                                <td><?php echo $proxy['success_count'] . '/' . $proxy['fail_count']; ?></td>
                                                <td><?php echo htmlspecialchars($proxy['remarks'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($proxy['created_at']); ?></td>
                                                <td>
                                                    <div class="actions">
                                                        <button type="button" class="btn btn-small" onclick="openEditModal('socks5', <?php echo htmlspecialchars(json_encode($proxy)); ?>)">编辑</button>
                                                        <button type="button" class="btn btn-small btn-success" onclick="testProxy('socks5', <?php echo $proxy['id']; ?>)" id="test-btn-socks5-<?php echo $proxy['id']; ?>">测试</button>
                                                        <button type="button" class="btn btn-danger btn-small" onclick="deleteProxy('socks5', <?php echo $proxy['id']; ?>)">删除</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Proxy Modal -->
    <div id="proxyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">添加代理</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="proxyForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="proxy_type" id="formProxyType" value="http">
                    <input type="hidden" name="id" id="formId" value="">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalName">代理名称 *</label>
                            <input type="text" id="modalName" name="name" required placeholder="例如：主代理服务器">
                        </div>
                        <div class="form-group">
                            <label for="modalHost">代理地址 *</label>
                            <input type="text" id="modalHost" name="host" required placeholder="例如：127.0.0.1 或 proxy.example.com">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalPort">端口 *</label>
                            <input type="number" id="modalPort" name="port" required min="1" max="65535" placeholder="例如：8080">
                        </div>
                        <div class="form-group">
                            <label for="modalUsername">用户名</label>
                            <input type="text" id="modalUsername" name="username" placeholder="代理用户名（可选）">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalPassword">密码</label>
                            <input type="password" id="modalPassword" name="password" placeholder="代理密码（可选）">
                        </div>
                        <div class="form-group">
                            <label for="modalRemarks">备注</label>
                            <input type="text" id="modalRemarks" name="remarks" placeholder="代理备注信息（可选）">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="testProxyModal()" id="testBtn">测试连接</button>
                    <button type="button" class="btn" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn" id="submitBtn">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Toast notification system
        function showToast(message, type = 'info', duration = 3000) {
            const container = document.getElementById('toast-container');
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            
            container.appendChild(toast);
            
            // Trigger animation
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Auto remove
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (container.contains(toast)) {
                        container.removeChild(toast);
                    }
                }, 300);
            }, duration);
        }
        
        // Show PHP messages as toasts on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($message): ?>
                showToast('<?php echo addslashes(htmlspecialchars($message)); ?>', 'success');
            <?php endif; ?>
            
            <?php if ($error): ?>
                showToast('<?php echo addslashes(htmlspecialchars($error)); ?>', 'error');
            <?php endif; ?>
        });
        
        // Tab switching functionality
        function switchTab(type) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(type + '-tab').classList.add('active');
        }
        
        // Modal control functions
        function openAddModal(proxyType) {
            document.getElementById('modalTitle').textContent = '添加' + (proxyType === 'socks5' ? 'SOCKS5' : 'HTTP') + '代理';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formProxyType').value = proxyType;
            document.getElementById('formId').value = '';
            document.getElementById('submitBtn').textContent = '添加代理';
            
            // Reset form
            document.getElementById('proxyForm').reset();
            
            showModal();
        }
        
        function openEditModal(proxyType, proxy) {
            document.getElementById('modalTitle').textContent = '编辑' + (proxyType === 'socks5' ? 'SOCKS5' : 'HTTP') + '代理';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formProxyType').value = proxyType;
            document.getElementById('formId').value = proxy.id;
            document.getElementById('submitBtn').textContent = '保存修改';
            
            // Fill form with existing data
            document.getElementById('modalName').value = proxy.name;
            document.getElementById('modalHost').value = proxy.host;
            document.getElementById('modalPort').value = proxy.port;
            document.getElementById('modalUsername').value = proxy.username || '';
            document.getElementById('modalPassword').value = proxy.password || '';
            document.getElementById('modalRemarks').value = proxy.remarks || '';
            
            showModal();
        }
        
        function showModal() {
            document.getElementById('proxyModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            document.getElementById('proxyModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Close modal when clicking outside
        document.getElementById('proxyModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Test proxy connection
        function testProxyModal() {
            const btn = document.getElementById('testBtn');
            const originalText = btn.textContent;
            
            // Get form data
            const data = {
                name: document.getElementById('modalName').value,
                host: document.getElementById('modalHost').value,
                port: parseInt(document.getElementById('modalPort').value),
                username: document.getElementById('modalUsername').value,
                password: document.getElementById('modalPassword').value,
                proxy_type: document.getElementById('formProxyType').value
            };
            
            // Validate required fields
            if (!data.name || !data.host || !data.port) {
                showToast('请填写代理名称、地址和端口', 'error');
                return;
            }
            
            // Show loading state
            btn.disabled = true;
            btn.textContent = '测试中...';
            
            fetch('api.php?action=test_proxy', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message, 'success', 5000);
                } else {
                    showToast(result.message, 'error', 8000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('❌ 测试连接时发生错误', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = originalText;
            });
        }
        
        // Test proxy for existing proxies
        function testProxy(proxyType, proxyId) {
            const btn = document.getElementById('test-btn-' + proxyType + '-' + proxyId);
            const originalText = btn.textContent;
            
            // Show loading state
            btn.disabled = true;
            btn.textContent = '测试中...';
            
            fetch('api.php?action=test_proxy', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'proxy_id=' + proxyId + '&proxy_type=' + proxyType
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message, 'success', 5000);
                } else {
                    showToast(result.message, 'error', 8000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('❌ 测试连接时发生错误', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = originalText;
            });
        }
        
        // Test all proxies
        function testAllProxies(proxyType) {
            showToast('开始测试所有' + (proxyType === 'socks5' ? 'SOCKS5' : 'HTTP') + '代理...', 'info');
            
            // This would be implemented as a batch operation
            // For now, just show a placeholder
            setTimeout(() => {
                showToast('批量测试功能正在开发中', 'info');
            }, 1000);
        }
        
        // Delete proxy with confirmation
        function deleteProxy(proxyType, proxyId) {
            if (confirm('确认删除这个' + (proxyType === 'socks5' ? 'SOCKS5' : 'HTTP') + '代理吗？此操作不可撤销。')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="proxy_type" value="${proxyType}">
                    <input type="hidden" name="id" value="${proxyId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Batch delete functionality
        function toggleSelectAll(proxyType) {
            const selectAll = document.getElementById('selectAll' + (proxyType === 'socks5' ? 'Socks5' : 'Http'));
            const checkboxes = document.querySelectorAll('.' + proxyType + '-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateBatchDeleteButton(proxyType);
        }
        
        function updateBatchDeleteButton(proxyType) {
            const checkboxes = document.querySelectorAll('.' + proxyType + '-checkbox:checked');
            const batchDeleteBtn = document.getElementById('batchDelete' + (proxyType === 'socks5' ? 'Socks5' : 'Http') + 'Btn');
            const selectedCount = document.getElementById('selected' + (proxyType === 'socks5' ? 'Socks5' : 'Http') + 'Count');
            
            if (checkboxes.length > 0) {
                batchDeleteBtn.style.display = 'inline-block';
                selectedCount.textContent = `已选择 ${checkboxes.length} 个${proxyType === 'socks5' ? 'SOCKS5' : 'HTTP'}代理`;
            } else {
                batchDeleteBtn.style.display = 'none';
                selectedCount.textContent = '';
            }
        }
        
        function batchDelete(proxyType) {
            const checkboxes = document.querySelectorAll('.' + proxyType + '-checkbox:checked');
            const ids = Array.from(checkboxes).map(cb => cb.value);
            
            if (ids.length === 0) {
                showToast('请选择要删除的代理', 'error');
                return;
            }
            
            const typeName = proxyType === 'socks5' ? 'SOCKS5' : 'HTTP';
            if (confirm(`确认删除选中的 ${ids.length} 个${typeName}代理吗？此操作不可撤销。`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="batch_delete">
                    <input type="hidden" name="proxy_type" value="${proxyType}">
                    ${ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('')}
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>