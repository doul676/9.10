<?php
/**
 * 代理池辅助工具类
 * 提供代理选择和管理功能
 */

class ProxyHelper {
    private $db;
    
    public function __construct() {
        $this->db = new SQLite3(__DIR__ . '/../../db/mail.sqlite');
        $this->ensureProxyTable();
    }
    
    /**
     * 确保代理表存在
     */
    private function ensureProxyTable() {
        $sql = "CREATE TABLE IF NOT EXISTS proxy_pool (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proxy_name TEXT NOT NULL DEFAULT '',
            proxy_type TEXT NOT NULL CHECK (proxy_type IN ('http', 'socks5')),
            proxy_host TEXT NOT NULL,
            proxy_port INTEGER NOT NULL,
            proxy_username TEXT DEFAULT '',
            proxy_password TEXT DEFAULT '',
            is_active INTEGER NOT NULL DEFAULT 1,
            is_verified INTEGER NOT NULL DEFAULT 0,
            last_test_time DATETIME DEFAULT NULL,
            test_success_count INTEGER DEFAULT 0,
            test_fail_count INTEGER DEFAULT 0,
            response_time INTEGER DEFAULT 0,
            remarks TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        
        $this->db->exec($sql);
        
        // 创建索引
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_proxy_pool_type ON proxy_pool(proxy_type)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_proxy_pool_active ON proxy_pool(is_active)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_proxy_pool_host_port ON proxy_pool(proxy_host, proxy_port)");
    }
    
    /**
     * 获取最佳可用代理
     * @return array|null 返回代理信息或null（无可用代理）
     */
    public function getBestProxy() {
        try {
            // 优先选择已验证且活跃的代理，按响应时间排序
            $sql = "SELECT * FROM proxy_pool 
                    WHERE is_active = 1 AND is_verified = 1 
                    ORDER BY response_time ASC, test_success_count DESC 
                    LIMIT 1";
            
            $result = $this->db->query($sql);
            $proxy = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($proxy) {
                return $proxy;
            }
            
            // 如果没有已验证的代理，尝试获取活跃的代理
            $sql = "SELECT * FROM proxy_pool 
                    WHERE is_active = 1 
                    ORDER BY test_success_count DESC, created_at DESC 
                    LIMIT 1";
            
            $result = $this->db->query($sql);
            return $result->fetchArray(SQLITE3_ASSOC) ?: null;
            
        } catch (Exception $e) {
            error_log('获取最佳代理失败: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 检查是否有可用代理
     * @return bool
     */
    public function hasActiveProxies() {
        try {
            $count = $this->db->querySingle('SELECT COUNT(*) FROM proxy_pool WHERE is_active = 1');
            return $count > 0;
        } catch (Exception $e) {
            error_log('检查代理可用性失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取所有活跃代理
     * @return array
     */
    public function getActiveProxies() {
        try {
            $sql = "SELECT * FROM proxy_pool 
                    WHERE is_active = 1 
                    ORDER BY is_verified DESC, response_time ASC";
            
            $result = $this->db->query($sql);
            $proxies = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $proxies[] = $row;
            }
            
            return $proxies;
        } catch (Exception $e) {
            error_log('获取活跃代理列表失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 更新代理使用统计
     * @param int $proxyId 代理ID
     * @param bool $success 是否成功
     * @param int $responseTime 响应时间（毫秒）
     */
    public function updateProxyStats($proxyId, $success, $responseTime = 0) {
        try {
            $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
            $timestamp = $beijingTime->format('Y-m-d H:i:s');
            
            if ($success) {
                $sql = "UPDATE proxy_pool 
                        SET is_verified = 1, 
                            last_test_time = ?, 
                            test_success_count = test_success_count + 1, 
                            response_time = ?, 
                            updated_at = ? 
                        WHERE id = ?";
            } else {
                $sql = "UPDATE proxy_pool 
                        SET is_verified = 0, 
                            last_test_time = ?, 
                            test_fail_count = test_fail_count + 1, 
                            updated_at = ? 
                        WHERE id = ?";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(1, $timestamp);
            
            if ($success) {
                $stmt->bindValue(2, $responseTime);
                $stmt->bindValue(3, $timestamp);
                $stmt->bindValue(4, $proxyId);
            } else {
                $stmt->bindValue(2, $timestamp);
                $stmt->bindValue(3, $proxyId);
            }
            
            $stmt->execute();
        } catch (Exception $e) {
            error_log('更新代理统计失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 测试代理连接（简单HTTP测试）
     * @param array $proxy 代理配置
     * @return array 测试结果
     */
    public function testProxyConnection($proxy) {
        try {
            $testUrls = [
                'http://httpbin.org/ip',
                'http://ipinfo.io/json',
                'http://ip-api.com/json'
            ];
            
            $timeout = 8;
            $connectTimeout = 5;
            
            foreach ($testUrls as $testUrl) {
                $startTime = microtime(true);
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $testUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_NOBODY, false);
                
                // 设置代理
                if ($proxy['proxy_type'] === 'http') {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                } elseif ($proxy['proxy_type'] === 'socks5') {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                }
                
                curl_setopt($ch, CURLOPT_PROXY, $proxy['proxy_host'] . ':' . $proxy['proxy_port']);
                
                if (!empty($proxy['proxy_username']) && !empty($proxy['proxy_password'])) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['proxy_username'] . ':' . $proxy['proxy_password']);
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                $actualTime = round((microtime(true) - $startTime) * 1000);
                
                if ($response !== false && empty($error) && $httpCode === 200) {
                    return [
                        'success' => true,
                        'message' => '代理连接测试成功',
                        'response_time' => $actualTime
                    ];
                }
            }
            
            return [
                'success' => false,
                'message' => '代理连接测试失败：所有测试URL均无法访问'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '代理测试失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 析构函数，关闭数据库连接
     */
    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}
?>