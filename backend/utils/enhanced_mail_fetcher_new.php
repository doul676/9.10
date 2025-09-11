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
            // 只支持IMAP协议，因为这是自定义实现
            if ($this->protocol !== 'imap') {
                throw new Exception('当前版本只支持IMAP协议');
            }
            
            // 创建代理客户端
            $proxy = $this->useProxy ? $this->currentProxy : null;
            $this->client = new ProxyImapClient(
                $this->server,
                $this->port,
                $this->username,
                $this->password,
                $this->ssl,
                $proxy
            );
            
            if ($this->useProxy && $this->currentProxy) {
                error_log('使用代理连接: ' . $this->currentProxy['proxy_type'] . '://' . 
                         $this->currentProxy['proxy_host'] . ':' . $this->currentProxy['proxy_port']);
            } else {
                error_log('使用直接连接');
            }
            
            // 尝试连接
            $startTime = microtime(true);
            $result = $this->client->connect();
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            // 更新代理统计（如果使用了代理）
            if ($this->useProxy && $this->currentProxy) {
                $this->proxyManager->updateProxyStats($this->currentProxy['id'], true, $responseTime);
                error_log('代理连接成功，响应时间: ' . $responseTime . 'ms');
            }
            
            return $result;
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            // 如果代理连接失败，尝试直连
            if ($this->useProxy && $this->currentProxy) {
                error_log('代理连接失败: ' . $errorMessage . ', 尝试直连...');
                $this->proxyManager->updateProxyStats($this->currentProxy['id'], false);
                
                // 重置代理状态并尝试直连
                $this->useProxy = false;
                $this->currentProxy = null;
                
                return $this->connect();
            }
            
            error_log('邮件连接失败: ' . $errorMessage);
            return false;
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
            $this->client->close();
            $this->client = null;
        }
    }
    
    /**
     * 测试连接
     */
    public function testConnection() {
        try {
            // 创建临时客户端进行测试
            $proxy = $this->useProxy ? $this->currentProxy : null;
            $testClient = new ProxyImapClient(
                $this->server,
                $this->port,
                $this->username,
                $this->password,
                $this->ssl,
                $proxy
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
                        'response_time' => $responseTime . 'ms'
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
                        null // 无代理
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
        return $this->currentProxy;
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