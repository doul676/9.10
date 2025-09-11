<?php
/**
 * 代理池状态API
 * 提供代理池统计信息
 */

session_start();

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未授权访问'
    ]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    $db = new SQLite3(__DIR__ . '/../db/mail.sqlite');
    
    // 检查表是否存在
    $tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='proxy_pool'");
    
    if (!$tableExists) {
        echo json_encode([
            'success' => true,
            'data' => [
                'total' => 0,
                'active' => 0,
                'verified' => 0,
                'http' => 0,
                'socks5' => 0,
                'last_used' => null
            ]
        ]);
        exit();
    }
    
    // 获取代理池统计信息
    $stats = [];
    
    // 总数
    $stats['total'] = $db->querySingle('SELECT COUNT(*) FROM proxy_pool');
    
    // 活跃代理数
    $stats['active'] = $db->querySingle('SELECT COUNT(*) FROM proxy_pool WHERE is_active = 1');
    
    // 已验证代理数
    $stats['verified'] = $db->querySingle('SELECT COUNT(*) FROM proxy_pool WHERE is_verified = 1');
    
    // HTTP代理数
    $stats['http'] = $db->querySingle("SELECT COUNT(*) FROM proxy_pool WHERE proxy_type = 'http'");
    
    // SOCKS5代理数
    $stats['socks5'] = $db->querySingle("SELECT COUNT(*) FROM proxy_pool WHERE proxy_type = 'socks5'");
    
    // 最近使用的代理
    $lastUsed = $db->querySingle('SELECT last_test_time FROM proxy_pool WHERE last_test_time IS NOT NULL ORDER BY last_test_time DESC LIMIT 1');
    $stats['last_used'] = $lastUsed;
    
    // 响应时间统计
    $avgResponseTime = $db->querySingle('SELECT AVG(response_time) FROM proxy_pool WHERE response_time > 0');
    $stats['avg_response_time'] = $avgResponseTime ? round($avgResponseTime) : 0;
    
    // 成功率统计
    $successRate = $db->querySingle('
        SELECT 
            CASE WHEN SUM(test_success_count + test_fail_count) > 0 
            THEN ROUND(CAST(SUM(test_success_count) AS FLOAT) / SUM(test_success_count + test_fail_count) * 100, 2)
            ELSE 0 END as success_rate
        FROM proxy_pool 
        WHERE test_success_count > 0 OR test_fail_count > 0
    ');
    $stats['success_rate'] = $successRate ?: 0;
    
    // 最佳代理（按响应时间和成功率）
    $bestProxy = $db->query('
        SELECT proxy_name, proxy_host, proxy_port, proxy_type, response_time,
               CASE WHEN (test_success_count + test_fail_count) > 0 
               THEN ROUND(CAST(test_success_count AS FLOAT) / (test_success_count + test_fail_count) * 100, 2)
               ELSE 0 END as success_rate
        FROM proxy_pool 
        WHERE is_active = 1 AND is_verified = 1 AND response_time > 0
        ORDER BY response_time ASC, success_rate DESC
        LIMIT 1
    ')->fetchArray(SQLITE3_ASSOC);
    
    $stats['best_proxy'] = $bestProxy;
    
    echo json_encode([
        'success' => true,
        'data' => $stats
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '获取代理池状态失败: ' . $e->getMessage()
    ]);
}
?>