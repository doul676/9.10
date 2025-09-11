<?php
/**
 * Enhanced Mail Fetcher with True Proxy Support
 * Replacement for webklex/php-imap with real proxy functionality
 */

require_once __DIR__ . '/proxy_imap_client_new.php';
require_once __DIR__ . '/proxy_manager.php';

class EnhancedMailFetcher {
    private $server;
    private $port;
    private $username;
    private $password;
    private $protocol;
    private $ssl;
    private $client;
    private $currentProxy;
    private $proxyManager;
    private $useProxy;
    private $proxyAttempted = false; // Track if proxy was attempted
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->protocol = strtolower($protocol);
        $this->ssl = $ssl;
        $this->currentProxy = null;
        
        // 初始化代理管理器
        $this->proxyManager = new ProxyManager();
        
        // 强制检查是否有可用代理，如果有则使用代理
        $this->useProxy = $this->shouldUseProxy();
        $this->proxyAttempted = false;
    }
    
    /**
     * 判断是否应该使用代理 - 强制优先使用代理
     */
    private function shouldUseProxy() {
        try {
            // 只要数据库中有活跃的代理，就优先使用代理
            $availableProxy = $this->proxyManager->getAvailableProxy('', false); // 包括未验证的代理
            if ($availableProxy) {
                $this->currentProxy = $availableProxy;
                error_log('检测到可用代理: ' . $availableProxy['proxy_type'] . '://' . 
                         $availableProxy['proxy_host'] . ':' . $availableProxy['proxy_port']);
                return true;
            }
            
            // 如果没有已验证的代理，尝试获取任何活跃的代理
            $anyProxy = $this->proxyManager->getAvailableProxy('', false);
            if ($anyProxy) {
                $this->currentProxy = $anyProxy;
                error_log('检测到未验证代理，将尝试使用: ' . $anyProxy['proxy_type'] . '://' . 
                         $anyProxy['proxy_host'] . ':' . $anyProxy['proxy_port']);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log('获取代理时出错: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        try {
            // 支持IMAP和POP3协议
            if ($this->protocol !== 'imap' && $this->protocol !== 'pop3') {
                throw new Exception('不支持的协议: ' . $this->protocol . '，只支持IMAP和POP3');
            }
            
            // 对于POP3，现在也支持代理连接了
            if ($this->protocol === 'pop3') {
                return $this->connectPOP3WithProxy();
            }
            
            // IMAP协议使用原有的代理客户端
            $proxy = $this->useProxy ? $this->currentProxy : null;
            
            // Mark that we're attempting to use proxy if available
            if ($this->useProxy && $this->currentProxy) {
                $this->proxyAttempted = true;
            }
            
            $this->client = new ProxyImapClient(
                $this->server,
                $this->port,
                $this->username,
                $this->password,
                $this->ssl,
                $proxy,
                $this->protocol
            );
            
            if ($this->useProxy && $this->currentProxy) {
                error_log('使用代理连接: ' . $this->currentProxy['proxy_type'] . '://' . 
                         $this->currentProxy['proxy_host'] . ':' . $this->currentProxy['proxy_port']);
            } else {
                error_log('使用直接连接到 ' . $this->server . ':' . $this->port);
            }
            
            // 尝试连接
            $startTime = microtime(true);
            $result = $this->client->connect();
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            if ($result) {
                // 更新代理统计（如果使用了代理）
                if ($this->useProxy && $this->currentProxy) {
                    $this->proxyManager->updateProxyStats($this->currentProxy['id'], true, $responseTime);
                    error_log('代理连接成功，响应时间: ' . $responseTime . 'ms');
                } else {
                    error_log('直接连接成功，响应时间: ' . $responseTime . 'ms');
                }
                return true;
            } else {
                throw new Exception('连接失败，服务器未响应');
            }
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            // 如果代理连接失败，尝试直连
            if ($this->useProxy && $this->currentProxy) {
                error_log('代理连接失败: ' . $errorMessage . ', 尝试直连...');
                $this->proxyManager->updateProxyStats($this->currentProxy['id'], false);
                
                // 重置代理状态并尝试直连
                $this->useProxy = false;
                $originalProxy = $this->currentProxy;
                $this->currentProxy = null;
                
                try {
                    // 递归调用进行直连尝试
                    return $this->connect();
                } catch (Exception $directConnectException) {
                    // 直连也失败，恢复代理信息用于错误报告
                    $this->currentProxy = $originalProxy;
                    error_log('直连也失败: ' . $directConnectException->getMessage());
                    throw new Exception('代理连接失败: ' . $errorMessage . '; 直连也失败: ' . $directConnectException->getMessage());
                }
            }
            
            error_log('邮件连接失败: ' . $errorMessage);
            return false;
        }
    }
    
    /**
     * POP3协议连接（现在支持代理）
     */
    private function connectPOP3WithProxy() {
        // 使用新的ProxyImapClient来支持POP3代理连接
        $proxy = $this->useProxy ? $this->currentProxy : null;
        
        // Mark that we're attempting to use proxy if available
        if ($this->useProxy && $this->currentProxy) {
            $this->proxyAttempted = true;
        }
        
        $this->client = new ProxyImapClient(
            $this->server,
            $this->port,
            $this->username,
            $this->password,
            $this->ssl,
            $proxy,
            'pop3'
        );
        
        if ($this->useProxy && $this->currentProxy) {
            error_log('POP3使用代理连接: ' . $this->currentProxy['proxy_type'] . '://' . 
                     $this->currentProxy['proxy_host'] . ':' . $this->currentProxy['proxy_port']);
        } else {
            error_log('POP3使用直接连接到 ' . $this->server . ':' . $this->port);
        }
        
        // 尝试连接
        $startTime = microtime(true);
        $result = $this->client->connect();
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($result) {
            // 更新代理统计（如果使用了代理）
            if ($this->useProxy && $this->currentProxy) {
                $this->proxyManager->updateProxyStats($this->currentProxy['id'], true, $responseTime);
                error_log('POP3代理连接成功，响应时间: ' . $responseTime . 'ms');
            } else {
                error_log('POP3直接连接成功，响应时间: ' . $responseTime . 'ms');
            }
            return true;
        } else {
            throw new Exception('POP3连接失败，服务器未响应');
        }
    }
    
    /**
     * 获取最新邮件
     */
    public function getLatestMail() {
        if (!$this->client) {
            throw new Exception('未连接到邮件服务器');
        }
        
        try {
            // 现在两种协议都使用ProxyImapClient，统一处理
            $result = $this->client->getLatestEmail();
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '获取邮件失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 关闭连接
     */
    public function close() {
        if ($this->client) {
            // 现在所有协议都使用ProxyImapClient的close方法
            $this->client->close();
            $this->client = null;
        }
    }
    
    /**
     * 测试连接
     */
    public function testConnection() {
        try {
            // 现在所有协议都使用ProxyImapClient，统一处理
            $proxy = $this->useProxy ? $this->currentProxy : null;
            $testClient = new ProxyImapClient(
                $this->server,
                $this->port,
                $this->username,
                $this->password,
                $this->ssl,
                $proxy,
                $this->protocol
            );
            
            $startTime = microtime(true);
            $result = $testClient->testConnection();
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            if ($result['success']) {
                // 更新代理统计（如果使用了代理）
                if ($this->useProxy && $this->currentProxy) {
                    $this->proxyManager->updateProxyStats($this->currentProxy['id'], true, $responseTime);
                }
                
                return [
                    'success' => true,
                    'message' => '✅ 邮箱连接测试成功！',
                    'diagnostics' => [
                        'connection_method' => $this->useProxy ? '通过代理连接' : '直接连接',
                        'proxy_info' => $this->currentProxy ? [
                            'type' => $this->currentProxy['proxy_type'],
                            'host' => $this->currentProxy['proxy_host'],
                            'port' => $this->currentProxy['proxy_port'],
                            'name' => $this->currentProxy['proxy_name'] ?? 'Unknown'
                        ] : null,
                        'server_info' => $this->server . ':' . $this->port,
                        'protocol' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                        'library' => 'Custom Proxy IMAP Client',
                        'response_time' => $responseTime . 'ms',
                        'proxy_supported' => true
                    ]
                ];
            } else {
                // 如果代理连接失败，尝试直连
                if ($this->useProxy && $this->currentProxy) {
                    error_log('代理连接测试失败，尝试直连...');
                    $this->proxyManager->updateProxyStats($this->currentProxy['id'], false);
                    
                    // 测试直连
                    $directClient = new ProxyImapClient(
                        $this->server,
                        $this->port,
                        $this->username,
                        $this->password,
                        $this->ssl,
                        null, // 无代理
                        $this->protocol
                    );
                    
                    $directResult = $directClient->testConnection();
                    if ($directResult['success']) {
                        return [
                            'success' => true,
                            'message' => '✅ 邮箱连接测试成功（直连）',
                            'diagnostics' => [
                                'connection_method' => '代理连接失败，使用直接连接',
                                'proxy_info' => null,
                                'server_info' => $this->server . ':' . $this->port,
                                'protocol' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                                'library' => 'Custom Proxy IMAP Client (fallback)',
                                'fallback' => true
                            ]
                        ];
                    }
                }
                
                return [
                    'success' => false,
                    'message' => '❌ 邮箱连接测试失败: ' . $result['message'],
                    'diagnostics' => [
                        'connection_method' => $this->useProxy ? '代理连接尝试失败' : '直接连接失败',
                        'proxy_available' => $this->proxyManager->getAvailableProxy('', false) !== null,
                        'suggestion' => '请检查服务器地址、端口、认证信息和网络连接'
                    ]
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ 连接测试失败: ' . $e->getMessage(),
                'diagnostics' => [
                    'error_details' => $e->getMessage(),
                    'connection_method' => $this->useProxy ? '代理连接失败' : '直接连接失败'
                ]
            ];
        }
    }
    
    /**
     * 获取当前使用的代理信息
     */
    public function getCurrentProxy() {
        // POP3协议现在也支持代理连接，返回实际使用的代理信息
        return $this->useProxy ? $this->currentProxy : null;
    }
    
    /**
     * 检查是否尝试过使用代理
     */
    public function wasProxyAttempted() {
        return $this->proxyAttempted;
    }
    
    /**
     * 设置是否使用代理
     */
    public function setUseProxy($useProxy) {
        $this->useProxy = $useProxy;
        if ($useProxy && !$this->proxyManager) {
            $this->proxyManager = new ProxyManager();
        }
    }
}
?>