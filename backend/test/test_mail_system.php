#!/usr/bin/env php
<?php
/**
 * 邮件系统功能测试脚本
 * 测试Python实现是否正常工作
 */

require_once __DIR__ . '/../utils/python_mail_fetcher.php';

echo "=== 邮件系统功能测试 ===\n\n";

// 测试Python脚本是否存在
$pythonScript = __DIR__ . '/../python/mail_handler.py';
if (!file_exists($pythonScript)) {
    echo "❌ Python脚本不存在: $pythonScript\n";
    exit(1);
}
echo "✅ Python脚本存在\n";

// 测试Python解释器
$pythons = ['python3', 'python'];
$pythonExec = null;
foreach ($pythons as $python) {
    $output = shell_exec("which $python 2>/dev/null");
    if (!empty(trim($output))) {
        $pythonExec = $python;
        break;
    }
}

if (!$pythonExec) {
    echo "❌ 未找到Python解释器\n";
    exit(1);
}
echo "✅ Python解释器: $pythonExec\n";

// 测试Python脚本基本功能
echo "\n--- 测试Python脚本基本功能 ---\n";
$command = "$pythonExec $pythonScript 2>&1";
$output = shell_exec($command);
$result = json_decode(trim($output), true);

if ($result && isset($result['success']) && !$result['success'] && strpos($result['message'], '缺少操作参数') !== false) {
    echo "✅ Python脚本基本功能正常\n";
} else {
    echo "❌ Python脚本基本功能异常\n";
    echo "输出: $output\n";
}

// 测试PHP桥接类
echo "\n--- 测试PHP桥接类 ---\n";
try {
    $fetcher = new PythonMailFetcher('imap.example.com', 993, 'test@example.com', 'password', 'imap', true);
    echo "✅ PythonMailFetcher创建成功\n";
    
    // 测试连接方法（不实际连接）
    if (method_exists($fetcher, 'connect')) {
        echo "✅ connect方法存在\n";
    }
    
    if (method_exists($fetcher, 'getLatestMail')) {
        echo "✅ getLatestMail方法存在\n";
    }
    
    if (method_exists($fetcher, 'testConnection')) {
        echo "✅ testConnection方法存在\n";
    }
    
    if (method_exists($fetcher, 'getProxyInfo')) {
        echo "✅ getProxyInfo方法存在\n";
    }
    
} catch (Exception $e) {
    echo "❌ PythonMailFetcher创建失败: " . $e->getMessage() . "\n";
}

// 测试新的MailFetcher类
echo "\n--- 测试新的MailFetcher类 ---\n";
try {
    require_once __DIR__ . '/../utils/mail_fetcher.php';
    $fetcher = new MailFetcher('imap.example.com', 993, 'test@example.com', 'password', 'imap', true);
    echo "✅ 新MailFetcher创建成功\n";
    
    // 检查是否使用了Python实现
    $proxyInfo = $fetcher->getProxyInfo();
    if (is_array($proxyInfo)) {
        echo "✅ getProxyInfo方法正常工作\n";
    }
    
} catch (Exception $e) {
    echo "❌ 新MailFetcher创建失败: " . $e->getMessage() . "\n";
}

// 检查数据库
echo "\n--- 检查数据库 ---\n";
$dbPath = __DIR__ . '/../../db/mail.sqlite';
if (file_exists($dbPath)) {
    echo "✅ 邮件数据库存在\n";
    
    try {
        $db = new SQLite3($dbPath);
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = [];
        while ($row = $result->fetchArray()) {
            $tables[] = $row['name'];
        }
        $db->close();
        
        echo "✅ 数据库表: " . implode(', ', $tables) . "\n";
        
        if (in_array('proxy_config', $tables)) {
            echo "✅ 代理配置表存在\n";
        } else {
            echo "⚠️  代理配置表不存在（这是正常的，如果未配置代理）\n";
        }
        
    } catch (Exception $e) {
        echo "❌ 数据库访问错误: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠️  邮件数据库不存在: $dbPath\n";
}

echo "\n=== 测试完成 ===\n";
echo "\n如果要进行实际邮件测试，请：\n";
echo "1. 确保已添加邮箱账号到系统\n";
echo "2. 访问前端页面或管理后台进行实际邮件获取测试\n";
echo "3. 查看日志以确认是否使用了Python实现\n";
?>