<?php
/**
 * 简化代理管理器
 * 负责基本的代理选择和管理
 */

class ProxyManager {
    private $db;
    
    public function __construct() {
        // 规范化数据库路径处理
        $dbPath = realpath(__DIR__ . '/../../db/mail.sqlite');
        if (!$dbPath || !file_exists($dbPath)) {
            // 备用路径方案
            $dbPath = realpath(dirname(dirname(__DIR__)) . '/db/mail.sqlite');
        }
        
        if (!$dbPath || !file_exists($dbPath)) {
            throw new Exception('数据库文件不存在，请检查数据库配置');
        }
        
        $this->db = new SQLite3($dbPath);
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
        
        // 创建全局代理设置表
        $globalSql = "CREATE TABLE IF NOT EXISTS global_proxy_settings (
            id INTEGER PRIMARY KEY,
            global_proxy_enabled INTEGER DEFAULT 0,
            auto_select_fastest INTEGER DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        
        $this->db->exec($globalSql);
        
        // 初始化全局设置
        $checkSql = "SELECT COUNT(*) as count FROM global_proxy_settings";
        $result = $this->db->query($checkSql);
        $row = $result->fetchArray();
        
        if ($row['count'] == 0) {
            $initSql = "INSERT INTO global_proxy_settings (id, global_proxy_enabled, auto_select_fastest) VALUES (1, 0, 1)";
            $this->db->exec($initSql);
        }
    }
    
    /**
     * 获取全局代理设置
     */
    public function getGlobalProxySettings() {
        $sql = "SELECT * FROM global_proxy_settings WHERE id = 1";
        $result = $this->db->query($sql);
        $settings = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$settings) {
            return [
                'global_proxy_enabled' => 0,
                'auto_select_fastest' => 1
            ];
        }
        
        return $settings;
    }
    
    /**
     * 更新全局代理设置
     */
    public function updateGlobalProxySettings($enabled, $autoSelectFastest = 1) {
        $sql = "UPDATE global_proxy_settings SET 
                global_proxy_enabled = ?, 
                auto_select_fastest = ?,
                updated_at = CURRENT_TIMESTAMP 
                WHERE id = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $enabled ? 1 : 0);
        $stmt->bindValue(2, $autoSelectFastest ? 1 : 0);
        
        return $stmt->execute();
    }
    
    /**
     * 获取最快的代理（如果启用全局代理）
     */
    public function getFastestProxy() {
        $settings = $this->getGlobalProxySettings();
        
        if (!$settings['global_proxy_enabled']) {
            return null;
        }
        
        $sql = "SELECT * FROM proxy_pool 
                WHERE is_active = 1 AND is_verified = 1
                ORDER BY 
                    CASE WHEN response_time > 0 THEN response_time ELSE 9999999 END ASC,
                    CASE WHEN (test_success_count + test_fail_count) > 0 
                         THEN CAST(test_success_count AS FLOAT) / (test_success_count + test_fail_count) 
                         ELSE 0 END DESC
                LIMIT 1";
        
        $result = $this->db->query($sql);
        $proxy = $result->fetchArray(SQLITE3_ASSOC);
        
        return $proxy ? $proxy : null;
    }
    
    /**
     * 获取所有可用代理
     */
    public function getAllProxies() {
        $sql = "SELECT * FROM proxy_pool ORDER BY created_at DESC";
        $result = $this->db->query($sql);
        
        $proxies = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $proxies[] = $row;
        }
        
        return $proxies;
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
?>