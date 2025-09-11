<?php
/**
 * 代理池管理器
 * 负责代理的选择、分配和状态管理
 */

class ProxyManager {
    private $db;
    
    public function __construct() {
        $this->db = new SQLite3(__DIR__ . '/../../db/mail.sqlite');
        $this->ensureProxyTableExists();
    }
    
    /**
     * 确保代理池表存在
     */
    private function ensureProxyTableExists() {
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
     * 获取可用的代理
     * @param string $type 代理类型 ('http' 或 'socks5')，为空则返回所有类型
     * @param bool $verifiedOnly 是否只返回已验证的代理
     * @return array|null 代理信息或null
     */
    public function getAvailableProxy($type = '', $verifiedOnly = true) {
        $whereConditions = ['is_active = 1'];
        $params = [];
        
        if (!empty($type) && in_array($type, ['http', 'socks5'])) {
            $whereConditions[] = 'proxy_type = ?';
            $params[] = $type;
        }
        
        if ($verifiedOnly) {
            $whereConditions[] = 'is_verified = 1';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 优先选择响应时间快的代理，如果响应时间为0则按成功率排序
        $sql = "SELECT * FROM proxy_pool 
                WHERE $whereClause 
                ORDER BY 
                    CASE WHEN response_time > 0 THEN response_time ELSE 9999999 END ASC,
                    CASE WHEN (test_success_count + test_fail_count) > 0 
                         THEN CAST(test_success_count AS FLOAT) / (test_success_count + test_fail_count) 
                         ELSE 0 END DESC,
                    test_success_count DESC
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param);
            }
        }
        
        $result = $stmt->execute();
        $proxy = $result->fetchArray(SQLITE3_ASSOC);
        
        return $proxy ? $proxy : null;
    }
    
    /**
     * 轮询获取代理（按顺序分配）
     * @param string $type 代理类型
     * @param bool $verifiedOnly 是否只返回已验证的代理
     * @return array|null 代理信息或null
     */
    public function getProxyRoundRobin($type = '', $verifiedOnly = true) {
        static $lastProxyId = 0;
        
        $whereConditions = ['is_active = 1'];
        $params = [];
        
        if (!empty($type) && in_array($type, ['http', 'socks5'])) {
            $whereConditions[] = 'proxy_type = ?';
            $params[] = $type;
        }
        
        if ($verifiedOnly) {
            $whereConditions[] = 'is_verified = 1';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 获取比上次ID大的第一个代理
        $sql = "SELECT * FROM proxy_pool 
                WHERE $whereClause AND id > ? 
                ORDER BY id ASC 
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param);
            }
        }
        $stmt->bindValue(count($params) + 1, $lastProxyId);
        
        $result = $stmt->execute();
        $proxy = $result->fetchArray(SQLITE3_ASSOC);
        
        // 如果没找到，从头开始
        if (!$proxy) {
            $sql = "SELECT * FROM proxy_pool 
                    WHERE $whereClause 
                    ORDER BY id ASC 
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            if (!empty($params)) {
                foreach ($params as $index => $param) {
                    $stmt->bindValue($index + 1, $param);
                }
            }
            
            $result = $stmt->execute();
            $proxy = $result->fetchArray(SQLITE3_ASSOC);
        }
        
        if ($proxy) {
            $lastProxyId = $proxy['id'];
        }
        
        return $proxy ? $proxy : null;
    }
    
    /**
     * 获取所有可用代理
     * @param string $type 代理类型
     * @param bool $verifiedOnly 是否只返回已验证的代理
     * @return array 代理列表
     */
    public function getAllAvailableProxies($type = '', $verifiedOnly = true) {
        $whereConditions = ['is_active = 1'];
        $params = [];
        
        if (!empty($type) && in_array($type, ['http', 'socks5'])) {
            $whereConditions[] = 'proxy_type = ?';
            $params[] = $type;
        }
        
        if ($verifiedOnly) {
            $whereConditions[] = 'is_verified = 1';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $sql = "SELECT * FROM proxy_pool 
                WHERE $whereClause 
                ORDER BY 
                    CASE WHEN response_time > 0 THEN response_time ELSE 9999999 END ASC,
                    CASE WHEN (test_success_count + test_fail_count) > 0 
                         THEN CAST(test_success_count AS FLOAT) / (test_success_count + test_fail_count) 
                         ELSE 0 END DESC";
        
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param);
            }
        }
        
        $result = $stmt->execute();
        $proxies = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $proxies[] = $row;
        }
        
        return $proxies;
    }
    
    /**
     * 随机获取代理
     * @param string $type 代理类型
     * @param bool $verifiedOnly 是否只返回已验证的代理
     * @return array|null 代理信息或null
     */
    public function getRandomProxy($type = '', $verifiedOnly = true) {
        $proxies = $this->getAllAvailableProxies($type, $verifiedOnly);
        
        if (empty($proxies)) {
            return null;
        }
        
        return $proxies[array_rand($proxies)];
    }
    
    /**
     * 更新代理使用统计
     * @param int $proxyId 代理ID
     * @param bool $success 是否成功
     * @param int $responseTime 响应时间（毫秒）
     */
    public function updateProxyStats($proxyId, $success, $responseTime = 0) {
        $beijingTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $timestamp = $beijingTime->format('Y-m-d H:i:s');
        
        if ($success) {
            $sql = "UPDATE proxy_pool SET 
                    test_success_count = test_success_count + 1,
                    is_verified = 1,
                    response_time = ?,
                    last_test_time = ?,
                    updated_at = ?
                    WHERE id = ?";
        } else {
            $sql = "UPDATE proxy_pool SET 
                    test_fail_count = test_fail_count + 1,
                    last_test_time = ?,
                    updated_at = ?
                    WHERE id = ?";
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($success) {
            $stmt->bindValue(1, $responseTime);
            $stmt->bindValue(2, $timestamp);
            $stmt->bindValue(3, $timestamp);
            $stmt->bindValue(4, $proxyId);
        } else {
            $stmt->bindValue(1, $timestamp);
            $stmt->bindValue(2, $timestamp);
            $stmt->bindValue(3, $proxyId);
        }
        
        $stmt->execute();
    }
    
    /**
     * 检查代理是否可用
     * @param array $proxy 代理信息
     * @return array 测试结果
     */
    public function testProxy($proxy) {
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
                $this->updateProxyStats($proxy['id'], true, $actualTime);
                return [
                    'success' => true,
                    'message' => '代理连接成功',
                    'response_time' => $actualTime
                ];
            }
        }
        
        $this->updateProxyStats($proxy['id'], false);
        return [
            'success' => false,
            'message' => '代理连接失败',
            'response_time' => 0
        ];
    }
    
    /**
     * 格式化代理为URL格式
     * @param array $proxy 代理信息
     * @return string 代理URL
     */
    public function formatProxyUrl($proxy) {
        $scheme = $proxy['proxy_type'] === 'socks5' ? 'socks5' : 'http';
        $auth = '';
        
        if (!empty($proxy['proxy_username']) && !empty($proxy['proxy_password'])) {
            $auth = urlencode($proxy['proxy_username']) . ':' . urlencode($proxy['proxy_password']) . '@';
        }
        
        return $scheme . '://' . $auth . $proxy['proxy_host'] . ':' . $proxy['proxy_port'];
    }
    
    /**
     * 关闭数据库连接
     */
    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}
?>