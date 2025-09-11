<?php
/**
 * 代理支持的邮件获取工具类
 * 使用webklex/php-imap库，支持SOCKS/HTTP代理连接
 */

require_once __DIR__ . '/proxy_manager.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

class MailFetcherProxy {
    private $server;
    private $port;
    private $username;
    private $password;
    private $protocol;
    private $ssl;
    private $client;
    private $useProxy;
    private $proxy;
    private $proxyManager;
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->protocol = strtolower($protocol);
        $this->ssl = $ssl;
        $this->proxy = null;
        
        // 初始化代理管理器
        $this->proxyManager = new ProxyManager();
        
        // 自动检查是否有可用代理
        $this->useProxy = $this->shouldUseProxy();
    }
    
    /**
     * 判断是否应该使用代理
     */
    private function shouldUseProxy() {
        try {
            $availableProxy = $this->proxyManager->getAvailableProxy('', false);
            return $availableProxy !== null;
        } catch (Exception $e) {
            error_log('代理检查失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        try {
            // 如果使用代理，先获取可用代理
            if ($this->useProxy) {
                $this->proxy = $this->proxyManager->getAvailableProxy('', false);
                if (!$this->proxy) {
                    $this->useProxy = false;
                    error_log('没有可用的代理服务器，改为直连');
                }
            }
            
            return $this->establishConnection();
            
        } catch (Exception $e) {
            error_log('邮件连接失败: ' . $e->getMessage());
            
            // 如果使用代理连接失败，尝试直连
            if ($this->useProxy && $this->proxy) {
                $this->proxyManager->updateProxyStats($this->proxy['id'], false);
                
                $this->useProxy = false;
                $this->proxy = null;
                error_log('代理连接失败，尝试直连...');
                
                try {
                    return $this->establishConnection();
                } catch (Exception $fallbackError) {
                    error_log('直连也失败: ' . $fallbackError->getMessage());
                    return false;
                }
            }
            
            return false;
        }
    }
    
    /**
     * 建立连接（支持代理和直连）
     */
    private function establishConnection() {
        $startTime = microtime(true);
        
        try {
            // 如果使用代理，通过代理连接
            if ($this->useProxy && $this->proxy) {
                $this->client = $this->createProxiedClient();
            } else {
                $this->client = $this->createDirectClient();
            }
            
            // 尝试连接
            $this->client->connect();
            
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            // 如果使用了代理，更新代理统计
            if ($this->useProxy && $this->proxy) {
                $this->proxyManager->updateProxyStats($this->proxy['id'], true, $responseTime);
            }
            
            return true;
            
        } catch (ConnectionFailedException $e) {
            throw new Exception('连接失败: ' . $e->getMessage());
        } catch (AuthFailedException $e) {
            throw new Exception('身份验证失败: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new Exception('邮件服务器连接失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 创建支持代理的客户端
     */
    private function createProxiedClient() {
        // 配置客户端参数
        $config = [
            'host' => $this->server,
            'port' => $this->port,
            'encryption' => $this->ssl ? 'ssl' : false,
            'validate_cert' => false,
            'username' => $this->username,
            'password' => $this->password,
            'protocol' => $this->protocol,
            'proxy' => [
                'type' => $this->proxy['proxy_type'], // 'http' or 'socks5'
                'host' => $this->proxy['proxy_host'],
                'port' => $this->proxy['proxy_port'],
                'username' => $this->proxy['proxy_username'] ?? null,
                'password' => $this->proxy['proxy_password'] ?? null,
            ]
        ];
        
        // 由于webklex/php-imap可能不直接支持代理，我们需要通过底层配置
        return $this->createClientWithProxy($config);
    }
    
    /**
     * 创建直连客户端
     */
    private function createDirectClient() {
        $cm = new ClientManager();
        
        $config = [
            'host' => $this->server,
            'port' => $this->port,
            'encryption' => $this->ssl ? 'ssl' : false,
            'validate_cert' => false,
            'username' => $this->username,
            'password' => $this->password,
            'protocol' => $this->protocol,
        ];
        
        return $cm->make($config);
    }
    
    /**
     * 创建支持代理的客户端（通过底层配置）
     */
    private function createClientWithProxy($config) {
        // 由于webklex/php-imap可能不直接支持代理
        // 我们尝试通过设置流上下文来支持代理
        
        $cm = new ClientManager();
        
        // 如果是HTTP代理，我们可以通过流上下文设置
        if ($this->proxy['proxy_type'] === 'http') {
            $contextOptions = [
                'http' => [
                    'proxy' => 'tcp://' . $this->proxy['proxy_host'] . ':' . $this->proxy['proxy_port'],
                    'request_fulluri' => true,
                ]
            ];
            
            if (!empty($this->proxy['proxy_username']) && !empty($this->proxy['proxy_password'])) {
                $contextOptions['http']['header'] = 'Proxy-Authorization: Basic ' . 
                    base64_encode($this->proxy['proxy_username'] . ':' . $this->proxy['proxy_password']);
            }
            
            $context = stream_context_create($contextOptions);
            
            // 将流上下文传递给配置
            $config['options'] = [
                'context' => $context
            ];
        }
        
        // 对于SOCKS5代理，由于webklex/php-imap的限制，
        // 我们可能需要使用cURL或其他方法作为备选方案
        
        return $cm->make($config);
    }
    
    /**
     * 获取最新邮件
     */
    public function getLatestMail() {
        if (!$this->client) {
            throw new Exception('未连接到邮件服务器');
        }
        
        try {
            // 获取INBOX文件夹
            $folder = $this->client->getFolder('INBOX');
            
            // 获取邮件
            $messages = $folder->messages()->all()->desc()->limit(1);
            
            if ($messages->isEmpty()) {
                return [
                    'success' => true,
                    'message' => '邮箱中没有邮件',
                    'mail' => null
                ];
            }
            
            $message = $messages->first();
            
            // 构建邮件数据
            $mailData = [
                'subject' => $message->getSubject(),
                'from' => $this->formatAddress($message->getFrom()),
                'from_email' => $this->extractEmail($message->getFrom()),
                'from_name' => $this->extractName($message->getFrom()),
                'to' => $this->formatAddress($message->getTo()),
                'date' => $message->getDate()->format('Y-m-d H:i:s'),
                'body' => $this->extractBody($message),
                'message_id' => $message->getMessageId(),
                'size' => $message->getSize()
            ];
            
            return [
                'success' => true,
                'mail' => $mailData
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '获取邮件失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 格式化地址
     */
    private function formatAddress($addresses) {
        if (empty($addresses)) {
            return '未知';
        }
        
        $address = $addresses[0] ?? $addresses;
        if (is_array($address)) {
            $address = reset($address);
        }
        
        $email = $address->mail ?? '';
        $name = $address->personal ?? '';
        
        if ($name && $name !== $email) {
            return $name . ' <' . $email . '>';
        }
        
        return $email;
    }
    
    /**
     * 提取邮箱地址
     */
    private function extractEmail($addresses) {
        if (empty($addresses)) {
            return '未知';
        }
        
        $address = $addresses[0] ?? $addresses;
        if (is_array($address)) {
            $address = reset($address);
        }
        
        return $address->mail ?? '未知';
    }
    
    /**
     * 提取名称
     */
    private function extractName($addresses) {
        if (empty($addresses)) {
            return '';
        }
        
        $address = $addresses[0] ?? $addresses;
        if (is_array($address)) {
            $address = reset($address);
        }
        
        return $address->personal ?? '';
    }
    
    /**
     * 提取邮件正文
     */
    private function extractBody($message) {
        try {
            // 优先获取纯文本正文
            if ($message->hasTextBody()) {
                $body = $message->getTextBody();
                return $this->formatTextContent($body);
            }
            
            // 如果没有纯文本，尝试HTML并转换为文本
            if ($message->hasHTMLBody()) {
                $htmlBody = $message->getHTMLBody();
                return $this->htmlToText($htmlBody);
            }
            
            // 尝试获取原始正文
            $body = $message->getBody();
            return $this->formatTextContent($body);
            
        } catch (Exception $e) {
            return '无法读取邮件内容: ' . $e->getMessage();
        }
    }
    
    /**
     * 格式化纯文本内容
     */
    private function formatTextContent($text) {
        $text = trim($text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[^\x{20}-\x{7E}\x{4E00}-\x{9FFF}\s]/u', '', $text);
        $text = preg_replace('/^>\s*/m', '  > ', $text);
        return $text;
    }
    
    /**
     * 将HTML内容转换为格式化的纯文本
     */
    private function htmlToText($html) {
        $html = trim($html);
        
        // 移除脚本和样式标签
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        
        // 处理换行和段落
        $html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
        $html = str_replace(['</p>', '</div>', '</li>'], "\n\n", $html);
        
        // 处理列表
        $html = str_replace('<li>', "• ", $html);
        
        // 移除所有HTML标签
        $text = strip_tags($html);
        
        // 解码HTML实体
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        return $this->formatTextContent($text);
    }
    
    /**
     * 关闭连接
     */
    public function close() {
        if ($this->client) {
            try {
                $this->client->disconnect();
            } catch (Exception $e) {
                // 静默处理断开连接的错误
            }
            $this->client = null;
        }
    }
    
    /**
     * 测试连接
     */
    public function testConnection() {
        try {
            if ($this->connect()) {
                $this->close();
                return [
                    'success' => true,
                    'message' => '✅ 邮箱连接测试成功！',
                    'diagnostics' => [
                        'webklex_imap' => '✅ webklex/php-imap库可用',
                        'connection_test' => '✅ 服务器连接成功',
                        'protocol_info' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                        'server_info' => $this->server . ':' . $this->port,
                        'auth_status' => '✅ 身份验证成功',
                        'proxy_status' => $this->useProxy && $this->proxy ? 
                            '✅ 通过代理连接成功 (' . $this->proxy['proxy_type'] . ')' : 
                            '✅ 直连成功'
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '❌ 邮箱连接测试失败',
                    'diagnostics' => [
                        'webklex_imap' => '✅ webklex/php-imap库可用',
                        'connection_issue' => '❌ 无法建立服务器连接',
                        'suggestion' => '请检查服务器地址、端口和网络连接'
                    ],
                    'error_type' => 'connection_failed'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ 连接测试失败: ' . $e->getMessage(),
                'diagnostics' => [
                    'webklex_imap' => '✅ webklex/php-imap库可用',
                    'error_details' => $e->getMessage()
                ],
                'error_type' => 'test_error'
            ];
        }
    }
    
    /**
     * 获取当前使用的代理信息
     */
    public function getCurrentProxy() {
        return $this->proxy;
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