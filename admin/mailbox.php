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
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $server = $_POST['server'] ?? '';
            $port = (int)($_POST['port'] ?? 0);
            $protocol = $_POST['protocol'] ?? 'imap';
            $ssl = isset($_POST['ssl']) ? 1 : 0;
            
            if ($email && $username && $password && $server && $port) {
                $stmt = $db->prepare('INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bindValue(1, $email);
                $stmt->bindValue(2, $username);
                $stmt->bindValue(3, $password);
                $stmt->bindValue(4, $server);
                $stmt->bindValue(5, $port);
                $stmt->bindValue(6, $protocol);
                $stmt->bindValue(7, $ssl);
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
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $server = $_POST['server'] ?? '';
            $port = (int)($_POST['port'] ?? 0);
            $protocol = $_POST['protocol'] ?? 'imap';
            $ssl = isset($_POST['ssl']) ? 1 : 0;
            
            if ($id > 0 && $email && $username && $password && $server && $port) {
                $stmt = $db->prepare('UPDATE mail_accounts SET email=?, username=?, password=?, server=?, port=?, protocol=?, ssl=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $stmt->bindValue(1, $email);
                $stmt->bindValue(2, $username);
                $stmt->bindValue(3, $password);
                $stmt->bindValue(4, $server);
                $stmt->bindValue(5, $port);
                $stmt->bindValue(6, $protocol);
                $stmt->bindValue(7, $ssl);
                $stmt->bindValue(8, $id);
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
            <a href="daili.php" class="nav-item">
                <i>🌐</i> 代理池
            </a>
            <a href="kami.php" class="nav-item">
                <i>🔑</i> 卡密管理
            </a>
            <a href="kamirizhi.php" class="nav-item">
                <i>📝</i> 卡密日志
            </a>
            <a href="mailbox.php" class="nav-item active">
                <i>📫</i> 邮箱管理
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
                    <h2 class="card-title">添加邮箱账号</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">邮箱地址</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label for="username">用户名</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="password">密码</label>
                                <input type="password" id="password" name="password" required>
                            </div>
                            <div class="form-group">
                                <label for="server">服务器地址</label>
                                <input type="text" id="server" name="server" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="port">端口</label>
                                <input type="number" id="port" name="port" value="993" required>
                            </div>
                            <div class="form-group">
                                <label for="protocol">协议</label>
                                <select id="protocol" name="protocol">
                                    <option value="imap" selected>IMAP</option>
                                    <option value="pop3">POP3</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="ssl" name="ssl" checked>
                                    <label for="ssl">启用SSL安全连接</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn">添加邮箱账号</button>
                    </form>
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
                                        <th>邮箱地址</th>
                                        <th>用户名</th>
                                        <th>服务器</th>
                                        <th>端口/协议</th>
                                        <th>SSL</th>
                                        <th>创建时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($accounts as $account): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($account['email']); ?></td>
                                            <td><?php echo htmlspecialchars($account['username']); ?></td>
                                            <td><?php echo htmlspecialchars($account['server']); ?></td>
                                            <td><?php echo $account['port'] . '/' . strtoupper($account['protocol']); ?></td>
                                            <td><?php echo $account['ssl'] ? '✅' : '❌'; ?></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($account['created_at'])); ?></td>
                                            <td>
                                                <div class="actions">
                                                    <button type="button" class="btn btn-small" onclick="toggleEdit(<?php echo $account['id']; ?>)">编辑</button>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $account['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('确定要删除这个邮箱账号吗？')">删除</button>
                                                    </form>
                                                </div>
                                                <div id="edit-form-<?php echo $account['id']; ?>" class="edit-form">
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="update">
                                                        <input type="hidden" name="id" value="<?php echo $account['id']; ?>">
                                                        <div class="form-row">
                                                            <div class="form-group">
                                                                <label>邮箱地址</label>
                                                                <input type="email" name="email" value="<?php echo htmlspecialchars($account['email']); ?>" required>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>用户名</label>
                                                                <input type="text" name="username" value="<?php echo htmlspecialchars($account['username']); ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="form-row">
                                                            <div class="form-group">
                                                                <label>密码</label>
                                                                <input type="password" name="password" value="<?php echo htmlspecialchars($account['password']); ?>" required>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>服务器地址</label>
                                                                <input type="text" name="server" value="<?php echo htmlspecialchars($account['server']); ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="form-row">
                                                            <div class="form-group">
                                                                <label>端口</label>
                                                                <input type="number" name="port" value="<?php echo $account['port']; ?>" required>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>协议</label>
                                                                <select name="protocol">
                                                                    <option value="imap" <?php echo $account['protocol'] === 'imap' ? 'selected' : ''; ?>>IMAP</option>
                                                                    <option value="pop3" <?php echo $account['protocol'] === 'pop3' ? 'selected' : ''; ?>>POP3</option>
                                                                </select>
                                                            </div>
                                                            <div class="form-group">
                                                                <div class="checkbox-group">
                                                                    <input type="checkbox" name="ssl" <?php echo $account['ssl'] ? 'checked' : ''; ?>>
                                                                    <label>启用SSL安全连接</label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="actions">
                                                            <button type="submit" class="btn btn-small">保存修改</button>
                                                            <button type="button" class="btn btn-small" onclick="toggleEdit(<?php echo $account['id']; ?>)">取消</button>
                                                        </div>
                                                    </form>
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
    
    <script>
        function toggleEdit(id) {
            const form = document.getElementById('edit-form-' + id);
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }
    </script>
</body>
</html>