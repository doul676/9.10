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
                $stmt = $db->prepare('INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bindValue(1, $email);
                $stmt->bindValue(2, $username);
                $stmt->bindValue(3, $password);
                $stmt->bindValue(4, $server);
                $stmt->bindValue(5, $port);
                $stmt->bindValue(6, $protocol);
                $stmt->bindValue(7, $ssl);
                $stmt->bindValue(8, $remarks);
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
                $stmt = $db->prepare('UPDATE mail_accounts SET email=?, username=?, password=?, server=?, port=?, protocol=?, ssl=?, remarks=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $stmt->bindValue(1, $email);
                $stmt->bindValue(2, $username);
                $stmt->bindValue(3, $password);
                $stmt->bindValue(4, $server);
                $stmt->bindValue(5, $port);
                $stmt->bindValue(6, $protocol);
                $stmt->bindValue(7, $ssl);
                $stmt->bindValue(8, $remarks);
                $stmt->bindValue(9, $id);
                $stmt->execute();
                $message = '邮箱账号更新成功';
            } else {
                $error = '请填写所有必需字段';
            }
        }
        
        $db->close();
    } catch (Exception $e) {
        $error = '操作失败：' . $e->getMessage();
    }
}

// 获取所有邮箱账号
$accounts = [];
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
    } catch (Exception $e) {
        // 忽略字段已存在的错误
        error_log('Database schema check: ' . $e->getMessage());
    }
    
    $result = $db->query('SELECT * FROM mail_accounts ORDER BY created_at DESC');
    while ($row = $result->fetchArray()) {
        $accounts[] = $row;
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
            <?php if ($message): ?>
                <div class="message success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
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
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
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
                                            <td><?php echo $index++; ?></td>
                                            <td><?php echo htmlspecialchars($account['email']); ?></td>
                                            <td><?php echo str_repeat('*', min(8, strlen($account['password']))); ?></td>
                                            <td><?php echo htmlspecialchars($account['server']); ?></td>
                                            <td><?php echo strtoupper($account['protocol']) . '/' . $account['port']; ?></td>
                                            <td><?php echo $account['ssl'] ? '✅ 是' : '❌ 否'; ?></td>
                                            <td><?php echo htmlspecialchars($account['remarks'] ?? ''); ?></td>
                                            <td><?php 
                                                // 设置为北京时间显示
                                                $created_time = new DateTime($account['created_at']);
                                                $created_time->setTimezone(new DateTimeZone('Asia/Shanghai'));
                                                echo $created_time->format('Y-m-d H:i'); 
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
    
    <script>
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
                alert('请填写所有必需字段');
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
                    alert('✅ ' + result.message);
                } else {
                    alert('❌ ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ 测试连接时发生错误');
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
                    alert('✅ ' + result.message);
                } else {
                    alert('❌ ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ 测试连接时发生错误');
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
    </script>
</body>
</html>