<?php
/**
 * Python邮件处理器桥接类
 * 用于在PHP中调用Python邮件处理脚本
 * 替代原有的php-imap实现
 */

class PythonMailFetcher {
    private $server;
    private $port;
    private $username;
    private $password;
    private $protocol;
    private $ssl;
    private $pythonScript;
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->protocol = strtolower($protocol);
        $this->ssl = $ssl;
        
        // Python脚本路径
        $this->pythonScript = __DIR__ . '/../python/mail_handler.py';
        
        // 检查Python脚本是否存在
        if (!file_exists($this->pythonScript)) {
            throw new Exception('Python邮件处理脚本不存在: ' . $this->pythonScript);
        }
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        // Python脚本会在需要时进行连接，这里返回true保持兼容性
        return true;
    }
    
    /**
     * 获取最新邮件
     */
    public function getLatestMail() {
        try {
            $command = $this->buildCommand('get_mail');
            $output = $this->executeCommand($command);
            
            $result = json_decode($output, true);
            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Python脚本返回数据格式错误'
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '调用Python脚本失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 测试连接
     */
    public function testConnection() {
        try {
            $command = $this->buildCommand('test_connection');
            $output = $this->executeCommand($command);
            
            $result = json_decode($output, true);
            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Python脚本返回数据格式错误'
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '调用Python脚本失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取代理信息
     */
    public function getProxyInfo() {
        // 尝试从最近的邮件获取结果中提取代理信息
        $result = $this->getLatestMail();
        return $result['proxy'] ?? ['enabled' => false, 'info' => null];
    }
    
    /**
     * 关闭连接（保持兼容性）
     */
    public function close() {
        // Python脚本会自动管理连接，这里不需要操作
        return true;
    }
    
    /**
     * 构建Python命令
     */
    private function buildCommand($action) {
        $python = $this->findPython();
        $ssl_str = $this->ssl ? 'true' : 'false';
        
        // 构建参数数组
        $args = [
            escapeshellarg($this->pythonScript),
            escapeshellarg($action),
            escapeshellarg($this->server),
            escapeshellarg($this->port),
            escapeshellarg($this->username),
            escapeshellarg($this->password),
            escapeshellarg($this->protocol),
            escapeshellarg($ssl_str)
        ];
        
        return $python . ' ' . implode(' ', $args) . ' 2>&1';
    }
    
    /**
     * 查找Python解释器
     */
    private function findPython() {
        $pythons = ['python3', 'python'];
        
        foreach ($pythons as $python) {
            $output = shell_exec("which $python 2>/dev/null");
            if (!empty(trim($output))) {
                return $python;
            }
        }
        
        // 如果找不到，尝试常见路径
        $common_paths = [
            '/usr/bin/python3',
            '/usr/bin/python',
            '/usr/local/bin/python3',
            '/usr/local/bin/python'
        ];
        
        foreach ($common_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        throw new Exception('找不到Python解释器，请确保Python已安装');
    }
    
    /**
     * 执行命令
     */
    private function executeCommand($command) {
        // 记录命令执行日志
        error_log("执行Python邮件命令: " . $command);
        
        // 执行命令
        $output = shell_exec($command);
        
        if ($output === null) {
            throw new Exception('Python脚本执行失败');
        }
        
        // 记录输出日志
        error_log("Python脚本输出: " . $output);
        
        return trim($output);
    }
}

/**
 * 向后兼容的MailFetcher类
 * 自动选择使用Python实现或PHP-imap实现
 */
class MailFetcher {
    private $implementation;
    private $server;
    private $port;
    private $username;
    private $password;
    private $protocol;
    private $ssl;
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->protocol = strtolower($protocol);
        $this->ssl = $ssl;
        
        // 优先使用Python实现
        try {
            $this->implementation = new PythonMailFetcher($server, $port, $username, $password, $protocol, $ssl);
            error_log("使用Python邮件处理实现");
        } catch (Exception $e) {
            // 如果Python实现失败，回退到原有的PHP-imap实现
            error_log("Python实现不可用，回退到PHP-imap: " . $e->getMessage());
            
            // 检查是否有原有的MailFetcher类文件
            $originalFile = __DIR__ . '/mail_fetcher_original.php';
            if (file_exists($originalFile)) {
                require_once $originalFile;
                $this->implementation = new OriginalMailFetcher($server, $port, $username, $password, $protocol, $ssl);
            } else {
                throw new Exception('邮件处理实现不可用: Python实现失败且找不到原有实现');
            }
        }
    }
    
    public function connect() {
        return $this->implementation->connect();
    }
    
    public function getLatestMail() {
        return $this->implementation->getLatestMail();
    }
    
    public function testConnection() {
        if (method_exists($this->implementation, 'testConnection')) {
            return $this->implementation->testConnection();
        } else {
            // 为原有实现提供兼容性
            try {
                if ($this->implementation->connect()) {
                    $this->implementation->close();
                    return [
                        'success' => true,
                        'message' => '✅ 邮箱连接测试成功！',
                        'diagnostics' => [
                            'connection_test' => '✅ 服务器连接成功',
                            'implementation' => 'PHP-IMAP'
                        ]
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => '❌ 邮箱连接测试失败'
                    ];
                }
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => '❌ 连接测试失败: ' . $e->getMessage()
                ];
            }
        }
    }
    
    public function getProxyInfo() {
        if (method_exists($this->implementation, 'getProxyInfo')) {
            return $this->implementation->getProxyInfo();
        } else {
            return ['enabled' => false, 'info' => null];
        }
    }
    
    public function close() {
        return $this->implementation->close();
    }
}
?>