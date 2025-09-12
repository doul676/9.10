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

// 处理邮箱账号操作
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = new SQLite3('../db/mail.sqlite');
        
        // 确保服务器表存在
        $db->exec('CREATE TABLE IF NOT EXISTS mail_servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            server_address TEXT NOT NULL,
            imap_port INTEGER DEFAULT 993,
            pop3_port INTEGER DEFAULT 995,
            imap_ssl BOOLEAN DEFAULT 1,
            pop3_ssl BOOLEAN DEFAULT 1,
            description TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        if ($action === 'add') {
            $email = $_POST['email'] ?? '';
            $username = $email; // 使用邮箱作为用户名
            $password = $_POST['password'] ?? '';
            $server = $_POST['server'] ?? '';
            $port = (int)($_POST['port'] ?? 0);
            $protocol = $_POST['protocol'] ?? 'imap';
            $ssl = isset($_POST['ssl']) ? 1 : 0;
            $remarks = $_POST['remarks'] ?? '';
            
            if ($email && $password && $server && $port) {
                // 使用北京时间作为创建时间
                $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
                $stmt = $db->prepare('INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bindValue(1, $email);
                $stmt->bindValue(2, $username);
                $stmt->bindValue(3, $password);
                $stmt->bindValue(4, $server);
                $stmt->bindValue(5, $port);
                $stmt->bindValue(6, $protocol);
                $stmt->bindValue(7, $ssl);
                $stmt->bindValue(8, $remarks);
                $stmt->bindValue(9, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(10, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->execute();
                $message = '邮箱账号添加成功';
            } else {
                $error = '请填写所有必需字段';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM mail_accounts WHERE id = ?');
                $stmt->bindValue(1, $id);
                $stmt->execute();
                $message = '邮箱账号删除成功';
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $email = $_POST['email'] ?? '';
            $username = $email; // 使用邮箱作为用户名
            $password = $_POST['password'] ?? '';
            $server = $_POST['server'] ?? '';
            $port = (int)($_POST['port'] ?? 0);
            $protocol = $_POST['protocol'] ?? 'imap';
            $ssl = isset($_POST['ssl']) ? 1 : 0;
            $remarks = $_POST['remarks'] ?? '';
            
            if ($id > 0 && $email && $password && $server && $port) {
                // 使用北京时间作为更新时间
                $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
                $stmt = $db->prepare('UPDATE mail_accounts SET email=?, username=?, password=?, server=?, port=?, protocol=?, ssl=?, remarks=?, updated_at=? WHERE id=?');
                $stmt->bindValue(1, $email);
                $stmt->bindValue(2, $username);
                $stmt->bindValue(3, $password);
                $stmt->bindValue(4, $server);
                $stmt->bindValue(5, $port);
                $stmt->bindValue(6, $protocol);
                $stmt->bindValue(7, $ssl);
                $stmt->bindValue(8, $remarks);
                $stmt->bindValue(9, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(10, $id);
                $stmt->execute();
                $message = '邮箱账号更新成功';
            } else {
                $error = '请填写所有必需字段';
            }
        } elseif ($action === 'batch_delete') {
            $ids = $_POST['ids'] ?? [];
            if (!empty($ids) && is_array($ids)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM mail_accounts WHERE id IN ($placeholders)");
                foreach ($ids as $index => $id) {
                    $stmt->bindValue($index + 1, (int)$id);
                }
                $stmt->execute();
                $message = '批量删除成功，共删除 ' . count($ids) . ' 个邮箱账号';
            } else {
                $error = '请选择要删除的邮箱账号';
            }
        } elseif ($action === 'add_server') {
            $name = $_POST['name'] ?? '';
            $server_address = $_POST['server_address'] ?? '';
            $imap_port = (int)($_POST['imap_port'] ?? 993);
            $pop3_port = (int)($_POST['pop3_port'] ?? 995);
            $imap_ssl = isset($_POST['imap_ssl']) ? 1 : 0;
            $pop3_ssl = isset($_POST['pop3_ssl']) ? 1 : 0;
            $description = $_POST['description'] ?? '';
            
            if ($name && $server_address) {
                $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
                $stmt = $db->prepare('INSERT INTO mail_servers (name, server_address, imap_port, pop3_port, imap_ssl, pop3_ssl, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $server_address);
                $stmt->bindValue(3, $imap_port);
                $stmt->bindValue(4, $pop3_port);
                $stmt->bindValue(5, $imap_ssl);
                $stmt->bindValue(6, $pop3_ssl);
                $stmt->bindValue(7, $description);
                $stmt->bindValue(8, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(9, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->execute();
                $message = '服务器地址添加成功';
            } else {
                $error = '请填写服务器名称和地址';
            }
        } elseif ($action === 'update_server') {
            $id = (int)($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $server_address = $_POST['server_address'] ?? '';
            $imap_port = (int)($_POST['imap_port'] ?? 993);
            $pop3_port = (int)($_POST['pop3_port'] ?? 995);
            $imap_ssl = isset($_POST['imap_ssl']) ? 1 : 0;
            $pop3_ssl = isset($_POST['pop3_ssl']) ? 1 : 0;
            $description = $_POST['description'] ?? '';
            
            if ($id > 0 && $name && $server_address) {
                $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
                $stmt = $db->prepare('UPDATE mail_servers SET name=?, server_address=?, imap_port=?, pop3_port=?, imap_ssl=?, pop3_ssl=?, description=?, updated_at=? WHERE id=?');
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $server_address);
                $stmt->bindValue(3, $imap_port);
                $stmt->bindValue(4, $pop3_port);
                $stmt->bindValue(5, $imap_ssl);
                $stmt->bindValue(6, $pop3_ssl);
                $stmt->bindValue(7, $description);
                $stmt->bindValue(8, $beijingTime->format('Y-m-d H:i:s'));
                $stmt->bindValue(9, $id);
                $stmt->execute();
                $message = '服务器地址更新成功';
            } else {
                $error = '请填写所有必需字段';
            }
        } elseif ($action === 'delete_server') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM mail_servers WHERE id = ?');
                $stmt->bindValue(1, $id);
                $stmt->execute();
                $message = '服务器地址删除成功';
            }
        }
        
        $db->close();
    } catch (Exception $e) {
        $error = '操作失败：' . $e->getMessage();
    }
}

// 获取所有邮箱账号
$accounts = [];
$servers = [];
try {
    $db = new SQLite3('../db/mail.sqlite');
    
    // 确保数据库表结构正确
    try {
        // 检查是否需要添加remarks字段
        $result = $db->query("PRAGMA table_info(mail_accounts)");
        $hasRemarks = false;
        while ($row = $result->fetchArray()) {
            if ($row['name'] === 'remarks') {
                $hasRemarks = true;
                break;
            }
        }
        
        if (!$hasRemarks) {
            $db->exec('ALTER TABLE mail_accounts ADD COLUMN remarks TEXT DEFAULT ""');
        }
        
        // 确保服务器表存在
        $db->exec('CREATE TABLE IF NOT EXISTS mail_servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            server_address TEXT NOT NULL,
            imap_port INTEGER DEFAULT 993,
            pop3_port INTEGER DEFAULT 995,
            imap_ssl BOOLEAN DEFAULT 1,
            pop3_ssl BOOLEAN DEFAULT 1,
            description TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
    } catch (Exception $e) {
        // 忽略字段已存在的错误
        error_log('Database schema check: ' . $e->getMessage());
    }
    
    $result = $db->query('SELECT * FROM mail_accounts ORDER BY created_at DESC');
    while ($row = $result->fetchArray()) {
        $accounts[] = $row;
    }
    
    // 获取所有服务器地址
    $result = $db->query('SELECT * FROM mail_servers ORDER BY created_at DESC');
    while ($row = $result->fetchArray()) {
        $servers[] = $row;
    }
    
    $db->close();
} catch (Exception $e) {
    $error = '获取邮箱列表失败：' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱管理 - 邮件查看系统</title>
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
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
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
        
        /* Edit Form */
        .edit-form {
            display: none;
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            margin-top: 10px;
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
        
        /* Loading and Test Connection Styles */
        .btn-loading {
            position: relative;
            opacity: 0.7;
            pointer-events: none;
        }
        
        .btn-loading::after {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            margin: auto;
            border: 2px solid transparent;
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .btn-success:hover {
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
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
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            max-width: 500px; /* Increased width for diagnostic messages */
        }
        
        .toast {
            padding: 15px 20px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transform: translateX(550px); /* Adjusted for wider container */
            opacity: 0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            word-wrap: break-word;
            white-space: pre-wrap; /* Preserve line breaks */
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .header-title {
                font-size: 20px;
            }
            
            .content {
                padding: 20px;
            }
            
            .actions {
                flex-direction: column;
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
            <a href="mailbox.php" class="nav-item active">
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
            <a href="system.php" class="nav-item">
                <i>⚙️</i> 系统设置
            </a>
        </nav>
        
        <!-- Quick Actions Panel -->
        <div style="padding: 20px; border-top: 1px solid rgba(255,255,255,0.1);">
            <button type="button" class="btn" onclick="openBatchAddModal()" style="width: 100%; margin-bottom: 10px; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);">
                📦 批量添加邮箱
            </button>
            <button type="button" class="btn" onclick="openServerModal()" style="width: 100%; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);">
                🌐 添加服务器地址
            </button>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1 class="header-title">邮箱管理</h1>
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
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">邮箱管理</h2>
                </div>
                <div class="card-body">
                    <button type="button" class="btn" onclick="openAddModal()">
                        ➕ 添加邮箱
                    </button>
                    <p style="margin-top: 15px; color: #64748b;">点击上方按钮添加新的邮箱账号</p>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">已添加的邮箱账号 (共 <?php echo count($accounts); ?> 个)</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($accounts)): ?>
                        <p>暂无邮箱账号，请添加第一个邮箱账号。</p>
                    <?php else: ?>
                        <div style="margin-bottom: 15px;">
                            <button type="button" class="btn btn-danger" onclick="batchDelete()" id="batchDeleteBtn" disabled>
                                🗑️ 批量删除
                            </button>
                            <span style="margin-left: 15px; color: #64748b;">
                                已选择: <span id="selectedCount">0</span> 个
                            </span>
                        </div>
                        
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                        </th>
                                        <th>序号</th>
                                        <th>邮箱账号</th>
                                        <th>邮箱密码</th>
                                        <th>服务器地址</th>
                                        <th>协议/端口</th>
                                        <th>是否启用SSL安全连接</th>
                                        <th>备注</th>
                                        <th>添加时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $index = 1;
                                    foreach ($accounts as $account): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="account-checkbox" value="<?php echo $account['id']; ?>" onchange="updateSelectedCount()">
                                            </td>
                                            <td><?php echo $index++; ?></td>
                                            <td><?php echo htmlspecialchars($account['email']); ?></td>
                                            <td><?php echo str_repeat('*', min(8, strlen($account['password']))); ?></td>
                                            <td><?php echo htmlspecialchars($account['server']); ?></td>
                                            <td><?php echo strtoupper($account['protocol']) . '/' . $account['port']; ?></td>
                                            <td><?php echo $account['ssl'] ? '✅ 是' : '❌ 否'; ?></td>
                                            <td><?php echo htmlspecialchars($account['remarks'] ?? ''); ?></td>
                                            <td><?php 
                                                // 显示北京时间
                                                try {
                                                    $timeString = $account['created_at'];
                                                    
                                                    // 智能判断时间格式：检查如果按北京时间解析，是否是未来时间
                                                    $dt_as_beijing = new DateTime($timeString, new DateTimeZone('Asia/Shanghai'));
                                                    $now = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
                                                    
                                                    // 如果时间在未来超过1小时，很可能是UTC时间被误当作本地时间
                                                    if ($dt_as_beijing->getTimestamp() > $now->getTimestamp() + 3600) {
                                                        // 当作UTC时间处理，转换为北京时间
                                                        $dt = new DateTime($timeString, new DateTimeZone('UTC'));
                                                        $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
                                                        echo $dt->format('Y-m-d H:i:s');
                                                    } else {
                                                        // 直接显示为北京时间
                                                        echo $dt_as_beijing->format('Y-m-d H:i:s');
                                                    }
                                                } catch (Exception $e) {
                                                    echo htmlspecialchars($account['created_at']);
                                                }
                                            ?></td>
                                            <td>
                                                <div class="actions">
                                                    <button type="button" class="btn btn-small" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($account)); ?>)">编辑</button>
                                                    <button type="button" class="btn btn-small btn-success" onclick="testConnection(<?php echo $account['id']; ?>)" id="test-btn-<?php echo $account['id']; ?>">测试连接</button>
                                                    <button type="button" class="btn btn-danger btn-small" onclick="deleteAccount(<?php echo $account['id']; ?>)">删除</button>
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
    
    <!-- Add/Edit Mailbox Modal -->
    <div id="mailboxModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">添加邮箱</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="mailboxForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalEmail">邮箱账号 *</label>
                            <input type="email" id="modalEmail" name="email" required placeholder="user@example.com">
                        </div>
                        <div class="form-group">
                            <label for="modalPassword">邮箱密码 *</label>
                            <input type="password" id="modalPassword" name="password" required placeholder="邮箱密码或授权码">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalServer">服务器地址 *</label>
                            <input type="text" id="modalServer" name="server" required placeholder="imap.example.com">
                        </div>
                        <div class="form-group">
                            <label for="modalProtocol">协议</label>
                            <select id="modalProtocol" name="protocol" onchange="updatePortByProtocol()">
                                <option value="imap" selected>IMAP</option>
                                <option value="pop3">POP3</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalPort">端口 *</label>
                            <input type="number" id="modalPort" name="port" value="993" required>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="modalSsl" name="ssl" checked onchange="updatePortBySsl()">
                                <label for="modalSsl">是否启用SSL安全连接</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalRemarks">备注</label>
                            <input type="text" id="modalRemarks" name="remarks" placeholder="可选备注信息">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="testConnectionModal()" id="testBtn">测试连接</button>
                    <button type="button" class="btn" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn" id="submitBtn">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Server Management Modal -->
    <div id="serverModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="modal-title">服务器地址管理</h3>
                <button type="button" class="modal-close" onclick="closeServerModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Add Server Form -->
                <form id="serverForm" method="POST" style="margin-bottom: 30px;">
                    <input type="hidden" name="action" id="serverAction" value="add_server">
                    <input type="hidden" name="id" id="serverId" value="">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="serverName">服务器名称 *</label>
                            <input type="text" id="serverName" name="name" required placeholder="如：QQ邮箱、Gmail等">
                        </div>
                        <div class="form-group">
                            <label for="serverAddress">服务器地址 *</label>
                            <input type="text" id="serverAddress" name="server_address" required placeholder="如：imap.qq.com">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="serverImapPort">IMAP端口</label>
                            <input type="number" id="serverImapPort" name="imap_port" value="993">
                        </div>
                        <div class="form-group">
                            <label for="serverPop3Port">POP3端口</label>
                            <input type="number" id="serverPop3Port" name="pop3_port" value="995">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="serverImapSsl" name="imap_ssl" checked>
                                <label for="serverImapSsl">IMAP启用SSL</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="serverPop3Ssl" name="pop3_ssl" checked>
                                <label for="serverPop3Ssl">POP3启用SSL</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="serverDescription">描述</label>
                            <input type="text" id="serverDescription" name="description" placeholder="可选描述信息">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn" id="serverSubmitBtn">添加服务器</button>
                    <button type="button" class="btn" onclick="resetServerForm()">重置</button>
                </form>
                
                <!-- Server List -->
                <h4>已添加的服务器地址</h4>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>名称</th>
                                <th>服务器地址</th>
                                <th>IMAP</th>
                                <th>POP3</th>
                                <th>描述</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="serverTableBody">
                            <?php foreach ($servers as $server): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($server['name']); ?></td>
                                    <td><?php echo htmlspecialchars($server['server_address']); ?></td>
                                    <td><?php echo $server['imap_port'] . ($server['imap_ssl'] ? ' (SSL)' : ''); ?></td>
                                    <td><?php echo $server['pop3_port'] . ($server['pop3_ssl'] ? ' (SSL)' : ''); ?></td>
                                    <td><?php echo htmlspecialchars($server['description'] ?? ''); ?></td>
                                    <td>
                                        <div class="actions">
                                            <button type="button" class="btn btn-small" onclick="editServer(<?php echo htmlspecialchars(json_encode($server)); ?>)">编辑</button>
                                            <button type="button" class="btn btn-danger btn-small" onclick="deleteServer(<?php echo $server['id']; ?>)">删除</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Batch Add Mailbox Modal -->
    <div id="batchAddModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title">批量添加邮箱</h3>
                <button type="button" class="modal-close" onclick="closeBatchAddModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: #64748b;">
                    请按以下格式输入邮箱信息，每行一个邮箱：<br>
                    <strong>邮箱地址|密码|备注（可选）</strong>
                </p>
                
                <div class="form-group">
                    <label for="batchEmailList">邮箱列表</label>
                    <textarea 
                        id="batchEmailList" 
                        rows="10" 
                        style="width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-family: monospace;"
                        placeholder="示例：
user1@qq.com|password123|QQ邮箱1
user2@gmail.com|apppassword|Gmail账号
user3@163.com|authcode|网易邮箱"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="batchServer">默认服务器</label>
                        <select id="batchServer" onchange="updateBatchServerConfig()">
                            <option value="">选择预设服务器</option>
                            <?php foreach ($servers as $server): ?>
                                <option value="<?php echo htmlspecialchars(json_encode($server)); ?>">
                                    <?php echo htmlspecialchars($server['name'] . ' - ' . $server['server_address']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom">自定义服务器</option>
                        </select>
                    </div>
                </div>
                
                <div id="batchServerConfig" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="batchServerAddress">服务器地址</label>
                            <input type="text" id="batchServerAddress" placeholder="imap.example.com">
                        </div>
                        <div class="form-group">
                            <label for="batchProtocol">协议</label>
                            <select id="batchProtocol" onchange="updateBatchPort()">
                                <option value="imap">IMAP</option>
                                <option value="pop3">POP3</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="batchPort">端口</label>
                            <input type="number" id="batchPort" value="993">
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="batchSsl" checked onchange="updateBatchPort()">
                                <label for="batchSsl">启用SSL</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeBatchAddModal()">取消</button>
                <button type="button" class="btn" onclick="processBatchAdd()">批量添加</button>
            </div>
        </div>
    </div>
    
    <script>
        // Toast notification system
        function showToast(message, type = 'info', duration = 3000) {
            const container = document.getElementById('toast-container');
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            // Handle multiline messages
            if (message.includes('\n')) {
                const lines = message.split('\n');
                const content = document.createElement('div');
                lines.forEach((line, index) => {
                    const lineDiv = document.createElement('div');
                    lineDiv.textContent = line;
                    if (index === 0) {
                        lineDiv.style.fontWeight = 'bold';
                        lineDiv.style.marginBottom = '8px';
                    } else if (line.trim().startsWith('•')) {
                        lineDiv.style.marginLeft = '10px';
                        lineDiv.style.fontSize = '13px';
                        lineDiv.style.opacity = '0.9';
                    }
                    content.appendChild(lineDiv);
                });
                toast.appendChild(content);
            } else {
                toast.textContent = message;
            }
            
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

        // Modal control functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = '添加邮箱';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('submitBtn').textContent = '添加邮箱';
            
            // Reset form
            document.getElementById('mailboxForm').reset();
            document.getElementById('modalProtocol').value = 'imap';
            document.getElementById('modalSsl').checked = true;
            
            // Set default port based on protocol and SSL
            updatePortByProtocol();
            
            showModal();
        }
        
        function openEditModal(account) {
            document.getElementById('modalTitle').textContent = '编辑邮箱';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formId').value = account.id;
            document.getElementById('submitBtn').textContent = '保存修改';
            
            // Fill form with existing data
            document.getElementById('modalEmail').value = account.email;
            document.getElementById('modalPassword').value = account.password;
            document.getElementById('modalServer').value = account.server;
            document.getElementById('modalPort').value = account.port;
            document.getElementById('modalProtocol').value = account.protocol;
            document.getElementById('modalRemarks').value = account.remarks || '';
            document.getElementById('modalSsl').checked = account.ssl == '1';
            
            showModal();
        }
        
        function showModal() {
            document.getElementById('mailboxModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            document.getElementById('mailboxModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Close modal when clicking outside
        document.getElementById('mailboxModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Test connection in modal
        function testConnectionModal() {
            const btn = document.getElementById('testBtn');
            const originalText = btn.textContent;
            
            // Get form data
            const data = {
                server: document.getElementById('modalServer').value,
                port: parseInt(document.getElementById('modalPort').value),
                username: document.getElementById('modalEmail').value, // 使用邮箱作为用户名
                password: document.getElementById('modalPassword').value,
                protocol: document.getElementById('modalProtocol').value,
                ssl: document.getElementById('modalSsl').checked
            };
            
            // Validate required fields
            if (!data.server || !data.username || !data.password || !data.port) {
                showToast('请填写所有必需字段', 'error');
                return;
            }
            
            // Show loading state
            btn.classList.add('btn-loading');
            btn.textContent = '测试中...';
            btn.disabled = true;
            
            fetch('api.php?action=test_connection', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    let message = result.message;
                    if (result.diagnostics) {
                        message += '\n\n诊断信息:';
                        for (const [key, value] of Object.entries(result.diagnostics)) {
                            message += '\n• ' + value;
                        }
                    }
                    showToast(message, 'success', 5000);
                } else {
                    let message = result.message;
                    if (result.diagnostics) {
                        message += '\n\n诊断信息:';
                        for (const [key, value] of Object.entries(result.diagnostics)) {
                            message += '\n• ' + value;
                        }
                    }
                    showToast(message, 'error', 8000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('❌ 测试连接时发生错误', 'error');
            })
            .finally(() => {
                btn.classList.remove('btn-loading');
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
        
        // Test connection for existing accounts
        function testConnection(accountId) {
            const btn = document.getElementById('test-btn-' + accountId);
            const originalText = btn.textContent;
            
            // For existing accounts, we need to get the password from the database
            // Let's use the account ID to fetch the data
            testConnectionById(accountId, btn);
        }
        
        function testConnectionById(accountId, btn) {
            const originalText = btn.textContent;
            
            // Show loading state
            btn.classList.add('btn-loading');
            btn.textContent = '测试中...';
            btn.disabled = true;
            
            fetch('api.php?action=test_connection', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'account_id=' + accountId
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    let message = result.message;
                    if (result.diagnostics) {
                        message += '\n\n诊断信息:';
                        for (const [key, value] of Object.entries(result.diagnostics)) {
                            message += '\n• ' + value;
                        }
                    }
                    showToast(message, 'success', 5000);
                } else {
                    let message = result.message;
                    if (result.diagnostics) {
                        message += '\n\n诊断信息:';
                        for (const [key, value] of Object.entries(result.diagnostics)) {
                            message += '\n• ' + value;
                        }
                    }
                    showToast(message, 'error', 8000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('❌ 测试连接时发生错误', 'error');
            })
            .finally(() => {
                btn.classList.remove('btn-loading');
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
        
        // Delete account with confirmation
        function deleteAccount(accountId) {
            if (confirm('确认删除这个邮箱账号吗？此操作不可撤销。')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${accountId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Dynamic port switching based on protocol and SSL
        function updatePortByProtocol() {
            const protocol = document.getElementById('modalProtocol').value;
            const ssl = document.getElementById('modalSsl').checked;
            const portInput = document.getElementById('modalPort');
            
            if (protocol === 'imap') {
                portInput.value = ssl ? '993' : '143';
            } else if (protocol === 'pop3') {
                portInput.value = ssl ? '995' : '110';
            }
        }
        
        function updatePortBySsl() {
            const protocol = document.getElementById('modalProtocol').value;
            const ssl = document.getElementById('modalSsl').checked;
            const portInput = document.getElementById('modalPort');
            
            if (protocol === 'imap') {
                portInput.value = ssl ? '993' : '143';
            } else if (protocol === 'pop3') {
                portInput.value = ssl ? '995' : '110';
            }
        }
        
        // Legacy function for compatibility
        function toggleEdit(id) {
            // This function is no longer used but kept for compatibility
        }
        
        // Batch delete functionality
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.account-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.account-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('batchDeleteBtn').disabled = count === 0;
        }
        
        function batchDelete() {
            const checkboxes = document.querySelectorAll('.account-checkbox:checked');
            if (checkboxes.length === 0) {
                showToast('请选择要删除的邮箱账号', 'error');
                return;
            }
            
            if (confirm(`确认删除选中的 ${checkboxes.length} 个邮箱账号吗？此操作不可撤销。`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                
                let formHTML = '<input type="hidden" name="action" value="batch_delete">';
                checkboxes.forEach(checkbox => {
                    formHTML += `<input type="hidden" name="ids[]" value="${checkbox.value}">`;
                });
                
                form.innerHTML = formHTML;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Server Management Functions
        function openServerModal() {
            resetServerForm();
            showServerModal();
        }
        
        function showServerModal() {
            document.getElementById('serverModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeServerModal() {
            document.getElementById('serverModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        function resetServerForm() {
            document.getElementById('serverForm').reset();
            document.getElementById('serverAction').value = 'add_server';
            document.getElementById('serverId').value = '';
            document.getElementById('serverSubmitBtn').textContent = '添加服务器';
            document.getElementById('serverImapPort').value = '993';
            document.getElementById('serverPop3Port').value = '995';
            document.getElementById('serverImapSsl').checked = true;
            document.getElementById('serverPop3Ssl').checked = true;
        }
        
        function editServer(server) {
            document.getElementById('serverAction').value = 'update_server';
            document.getElementById('serverId').value = server.id;
            document.getElementById('serverName').value = server.name;
            document.getElementById('serverAddress').value = server.server_address;
            document.getElementById('serverImapPort').value = server.imap_port;
            document.getElementById('serverPop3Port').value = server.pop3_port;
            document.getElementById('serverImapSsl').checked = server.imap_ssl == '1';
            document.getElementById('serverPop3Ssl').checked = server.pop3_ssl == '1';
            document.getElementById('serverDescription').value = server.description || '';
            document.getElementById('serverSubmitBtn').textContent = '更新服务器';
        }
        
        function deleteServer(serverId) {
            if (confirm('确认删除这个服务器地址吗？此操作不可撤销。')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_server">
                    <input type="hidden" name="id" value="${serverId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Batch Add Functions
        function openBatchAddModal() {
            document.getElementById('batchAddModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeBatchAddModal() {
            document.getElementById('batchAddModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        function updateBatchServerConfig() {
            const select = document.getElementById('batchServer');
            const config = document.getElementById('batchServerConfig');
            
            if (select.value === 'custom') {
                config.style.display = 'block';
            } else if (select.value) {
                // Pre-fill with selected server
                try {
                    const server = JSON.parse(select.value);
                    document.getElementById('batchServerAddress').value = server.server_address;
                    document.getElementById('batchProtocol').value = 'imap';
                    document.getElementById('batchPort').value = server.imap_port;
                    document.getElementById('batchSsl').checked = server.imap_ssl == '1';
                } catch (e) {
                    console.error('Error parsing server data:', e);
                }
                config.style.display = 'none';
            } else {
                config.style.display = 'none';
            }
        }
        
        function updateBatchPort() {
            const protocol = document.getElementById('batchProtocol').value;
            const ssl = document.getElementById('batchSsl').checked;
            const portInput = document.getElementById('batchPort');
            
            if (protocol === 'imap') {
                portInput.value = ssl ? '993' : '143';
            } else if (protocol === 'pop3') {
                portInput.value = ssl ? '995' : '110';
            }
        }
        
        function processBatchAdd() {
            const emailList = document.getElementById('batchEmailList').value.trim();
            if (!emailList) {
                showToast('请输入邮箱列表', 'error');
                return;
            }
            
            // Get server configuration
            const serverSelect = document.getElementById('batchServer');
            let serverConfig = {};
            
            if (serverSelect.value === 'custom') {
                serverConfig = {
                    server: document.getElementById('batchServerAddress').value,
                    protocol: document.getElementById('batchProtocol').value,
                    port: parseInt(document.getElementById('batchPort').value),
                    ssl: document.getElementById('batchSsl').checked
                };
            } else if (serverSelect.value) {
                try {
                    const server = JSON.parse(serverSelect.value);
                    serverConfig = {
                        server: server.server_address,
                        protocol: 'imap',
                        port: server.imap_port,
                        ssl: server.imap_ssl == '1'
                    };
                } catch (e) {
                    showToast('服务器配置错误', 'error');
                    return;
                }
            } else {
                showToast('请选择服务器配置', 'error');
                return;
            }
            
            if (!serverConfig.server) {
                showToast('请配置服务器地址', 'error');
                return;
            }
            
            // Parse email list
            const lines = emailList.split('\n').filter(line => line.trim());
            let successCount = 0;
            let errorCount = 0;
            
            // Process each email
            const processNext = (index) => {
                if (index >= lines.length) {
                    showToast(`批量添加完成！成功：${successCount} 个，失败：${errorCount} 个`, 'success');
                    if (successCount > 0) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    }
                    return;
                }
                
                const line = lines[index].trim();
                const parts = line.split('|');
                
                if (parts.length < 2) {
                    errorCount++;
                    showToast(`第 ${index + 1} 行格式错误，跳过`, 'error', 1000);
                    processNext(index + 1);
                    return;
                }
                
                const [email, password, remarks] = parts;
                
                // Add the account
                const formData = new FormData();
                formData.append('action', 'add');
                formData.append('email', email.trim());
                formData.append('password', password.trim());
                formData.append('server', serverConfig.server);
                formData.append('port', serverConfig.port);
                formData.append('protocol', serverConfig.protocol);
                if (serverConfig.ssl) formData.append('ssl', '1');
                if (remarks) formData.append('remarks', remarks.trim());
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(() => {
                    successCount++;
                    processNext(index + 1);
                })
                .catch(() => {
                    errorCount++;
                    processNext(index + 1);
                });
            };
            
            closeBatchAddModal();
            showToast('开始批量添加邮箱...', 'info');
            processNext(0);
        }
        
        // Close modals when clicking outside
        document.getElementById('serverModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeServerModal();
            }
        });
        
        document.getElementById('batchAddModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeBatchAddModal();
            }
        });
    </script>
</body>
</html>