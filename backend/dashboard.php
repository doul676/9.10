<?php
session_start();

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
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
        } elseif ($action === 'edit') {
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
    <title>控制面板 - 邮件查看系统</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-group.small {
            flex: 0 0 120px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .table th, .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-ssl {
            background: #d4edda;
            color: #155724;
        }
        
        .status-normal {
            background: #fff3cd;
            color: #856404;
        }
        
        .actions {
            display: flex;
            gap: 5px;
        }
        
        .edit-form {
            display: none;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .header-content {
                flex-direction: column;
                gap: 10px;
            }
            
            .table {
                font-size: 12px;
            }
            
            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>邮件查看系统 - 控制面板</h1>
            <div class="user-info">
                <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <a href="?logout=1" class="logout-btn">退出登录</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>添加邮箱账号</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">邮箱地址</label>
                        <input type="email" id="email" name="email" required placeholder="user@example.com">
                    </div>
                    <div class="form-group">
                        <label for="username">用户名</label>
                        <input type="text" id="username" name="username" required placeholder="通常与邮箱地址相同">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">密码</label>
                        <input type="password" id="password" name="password" required placeholder="邮箱密码或授权码">
                    </div>
                    <div class="form-group">
                        <label for="server">服务器地址</label>
                        <input type="text" id="server" name="server" required placeholder="imap.example.com">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group small">
                        <label for="port">端口</label>
                        <input type="number" id="port" name="port" required placeholder="993">
                    </div>
                    <div class="form-group small">
                        <label for="protocol">协议</label>
                        <select id="protocol" name="protocol">
                            <option value="imap">IMAP</option>
                            <option value="pop3">POP3</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="checkbox-group">
                            <input type="checkbox" id="ssl" name="ssl" checked>
                            <label for="ssl">启用SSL安全连接</label>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn">添加邮箱账号</button>
            </form>
        </div>
        
        <div class="card">
            <h2>已添加的邮箱账号 (共 <?php echo count($accounts); ?> 个)</h2>
            
            <?php if (empty($accounts)): ?>
                <p>暂无邮箱账号，请添加第一个邮箱账号。</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>邮箱地址</th>
                            <th>服务器</th>
                            <th>协议/端口</th>
                            <th>SSL</th>
                            <th>添加时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($account['email']); ?></td>
                                <td><?php echo htmlspecialchars($account['server']); ?></td>
                                <td><?php echo strtoupper($account['protocol']) . ':' . $account['port']; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $account['ssl'] ? 'status-ssl' : 'status-normal'; ?>">
                                        <?php echo $account['ssl'] ? 'SSL' : '普通'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($account['created_at'])); ?></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-small" onclick="toggleEdit(<?php echo $account['id']; ?>)">编辑</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('确认删除这个邮箱账号吗？')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $account['id']; ?>">
                                            <button type="submit" class="btn btn-small btn-danger">删除</button>
                                        </form>
                                    </div>
                                    
                                    <div id="edit-form-<?php echo $account['id']; ?>" class="edit-form">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="edit">
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
                                                    <label>服务器</label>
                                                    <input type="text" name="server" value="<?php echo htmlspecialchars($account['server']); ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="form-row">
                                                <div class="form-group small">
                                                    <label>端口</label>
                                                    <input type="number" name="port" value="<?php echo $account['port']; ?>" required>
                                                </div>
                                                <div class="form-group small">
                                                    <label>协议</label>
                                                    <select name="protocol">
                                                        <option value="imap" <?php echo $account['protocol'] === 'imap' ? 'selected' : ''; ?>>IMAP</option>
                                                        <option value="pop3" <?php echo $account['protocol'] === 'pop3' ? 'selected' : ''; ?>>POP3</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label>&nbsp;</label>
                                                    <div class="checkbox-group">
                                                        <input type="checkbox" name="ssl" <?php echo $account['ssl'] ? 'checked' : ''; ?>>
                                                        <label>启用SSL</label>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div style="margin-top: 15px;">
                                                <button type="submit" class="btn btn-small">保存</button>
                                                <button type="button" class="btn btn-small" onclick="toggleEdit(<?php echo $account['id']; ?>)">取消</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
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

<?php
// 处理退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit();
}
?>