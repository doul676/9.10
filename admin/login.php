<?php
session_start();

// 如果已登录，重定向到控制面板
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: ./');
    exit();
}

$error = '';

if ($_POST) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        try {
            $db = new SQLite3('../db/admin.sqlite');
            $stmt = $db->prepare('SELECT * FROM admins WHERE username = ?');
            $stmt->bindValue(1, $username);
            $result = $stmt->execute();
            $admin = $result->fetchArray();
            
            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                header('Location: ./');
                exit();
            } else {
                $error = '用户名或密码错误';
            }
            
            $db->close();
        } catch (Exception $e) {
            $error = '数据库连接失败：' . $e->getMessage();
        }
    } else {
        $error = '请输入用户名和密码';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - 邮件查看系统</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            animation: gradientShift 15s ease infinite;
        }
        
        @keyframes gradientShift {
            0%, 100% { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            25% { background: linear-gradient(135deg, #764ba2 0%, #667eea 100%); }
            50% { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); }
            75% { background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); }
        }
        
        body.dark-mode {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            animation: darkGradientShift 15s ease infinite;
        }
        
        @keyframes darkGradientShift {
            0%, 100% { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); }
            25% { background: linear-gradient(135deg, #334155 0%, #1e293b 100%); }
            50% { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); }
            75% { background: linear-gradient(135deg, #374151 0%, #1f2937 100%); }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.1), 0 15px 35px rgba(0, 0, 0, 0.07);
            width: 100%;
            max-width: 450px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideInUp 0.8s ease-out;
            transition: all 0.3s ease;
            position: relative;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        body.dark-mode .login-container {
            background: rgba(30, 41, 59, 0.95);
            color: white;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.3), 0 15px 35px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }
        
        .theme-toggle {
            position: absolute;
            top: -15px;
            right: -15px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 50%;
            width: 45px;
            height: 45px;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            z-index: 10;
        }
        
        .theme-toggle:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1) rotate(180deg);
        }
        
        body.dark-mode .theme-toggle {
            background: rgba(0, 0, 0, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        body.dark-mode .theme-toggle:hover {
            background: rgba(0, 0, 0, 0.3);
        }
        
        .login-header h1 {
            color: #333;
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: titleGlow 2s ease-in-out infinite alternate;
        }
        
        @keyframes titleGlow {
            from { filter: brightness(1); }
            to { filter: brightness(1.2); }
        }
        
        body.dark-mode .login-header h1 {
            background: linear-gradient(135deg, #60a5fa, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-header p {
            color: #666;
            font-size: 16px;
            opacity: 0.8;
        }
        
        body.dark-mode .login-header p {
            color: #cbd5e1;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        body.dark-mode .form-group label {
            color: #e2e8f0;
        }
        
        .form-group input {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
            position: relative;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            background: white;
            transform: translateY(-2px);
        }
        
        body.dark-mode .form-group input {
            background: rgba(30, 41, 59, 0.7);
            border-color: #475569;
            color: white;
        }
        
        body.dark-mode .form-group input:focus {
            background: rgba(30, 41, 59, 0.9);
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.1);
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: #666;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        body.dark-mode .password-toggle {
            color: #cbd5e1;
        }
        
        body.dark-mode .password-toggle:hover {
            color: #60a5fa;
        }
        
        .error {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: #dc2626;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid #fecaca;
            border-left: 4px solid #dc2626;
            animation: slideInDown 0.5s ease-out;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.1);
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        body.dark-mode .error {
            background: linear-gradient(135deg, #450a0a, #7f1d1d);
            color: #fca5a5;
            border-color: #991b1b;
            border-left-color: #ef4444;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.2);
        }
        
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .login-btn:hover::before {
            left: 100%;
        }
        
        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .login-btn:active {
            transform: translateY(-1px);
        }
        
        body.dark-mode .login-btn {
            background: linear-gradient(135deg, #60a5fa 0%, #a855f7 100%);
        }
        
        body.dark-mode .login-btn:hover {
            box-shadow: 0 10px 25px rgba(96, 165, 250, 0.4);
        }
        

    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <button class="theme-toggle" onclick="toggleTheme()" title="切换主题">🌙</button>
            <h1>管理员登录</h1>
            <p>邮件查看系统后台管理</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
                <button type="button" class="password-toggle" onclick="togglePassword()" title="显示/隐藏密码">👁️</button>
            </div>
            
            <button type="submit" class="login-btn">登录</button>
        </form>
        

    </div>
    
    <script>
        // Theme toggle functionality
        function toggleTheme() {
            const body = document.body;
            const themeToggle = document.querySelector('.theme-toggle');
            
            body.classList.toggle('dark-mode');
            
            if (body.classList.contains('dark-mode')) {
                themeToggle.textContent = '☀️';
                themeToggle.title = '切换到明亮模式';
                localStorage.setItem('theme', 'dark');
            } else {
                themeToggle.textContent = '🌙';
                themeToggle.title = '切换到暗黑模式';
                localStorage.setItem('theme', 'light');
            }
        }
        
        // Password visibility toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.password-toggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.textContent = '🙈';
                toggleButton.title = '隐藏密码';
            } else {
                passwordInput.type = 'password';
                toggleButton.textContent = '👁️';
                toggleButton.title = '显示密码';
            }
        }
        
        // Load saved theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme');
            const themeToggle = document.querySelector('.theme-toggle');
            
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                themeToggle.textContent = '☀️';
                themeToggle.title = '切换到明亮模式';
            }
        });
        
        // Add form animation
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = document.querySelector('.login-btn');
            submitBtn.style.transform = 'scale(0.95)';
            submitBtn.style.opacity = '0.8';
            setTimeout(() => {
                submitBtn.style.transform = '';
                submitBtn.style.opacity = '';
            }, 150);
        });
    </script>
</body>
</html>