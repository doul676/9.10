<?php
/**
 * Enhanced Mail Fetcher with Proxy Support
 * Uses webklex/php-imap library for proxy-enabled IMAP connections
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/proxy_manager.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;

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
            $cm = new ClientManager();
            
            // 构建配置
            $config = [
                'host' => $this->server,
                'port' => $this->port,
                'encryption' => $this->ssl ? 'ssl' : 'none',
                'validate_cert' => false, // 对于测试环境禁用证书验证
                'username' => $this->username,
                'password' => $this->password,
                'protocol' => $this->protocol,
                'timeout' => 30
            ];
            
            // 创建客户端
            $this->client = $cm->make($config);
            
            // 如果有代理配置，设置代理
            if ($this->useProxy && $this->currentProxy) {
                $proxyConfig = $this->buildProxyConfig($this->currentProxy);
                
                // 使用反射设置私有属性 proxy
                $reflection = new ReflectionClass($this->client);
                $proxyProperty = $reflection->getProperty('proxy');
                $proxyProperty->setAccessible(true);
                $proxyProperty->setValue($this->client, $proxyConfig);
                
                error_log('使用代理连接: ' . $this->currentProxy['proxy_type'] . '://' . 
                         $this->currentProxy['proxy_host'] . ':' . $this->currentProxy['proxy_port']);
            }
            
            // 尝试连接
            $startTime = microtime(true);
            $this->client->connect();
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            // 更新代理统计（如果使用了代理）
            if ($this->useProxy && $this->currentProxy) {
                $this->proxyManager->updateProxyStats($this->currentProxy['id'], true, $responseTime);
            }
            
            return true;
            
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
     * 构建代理配置
     */
    private function buildProxyConfig($proxy) {
        $proxyConfig = [];
        
        if ($proxy['proxy_type'] === 'http') {
            // HTTP 代理配置
            $proxyUrl = 'tcp://' . $proxy['proxy_host'] . ':' . $proxy['proxy_port'];
            
            $proxyConfig = [
                'socket' => $proxyUrl,
                'request_fulluri' => true,
                'username' => $proxy['proxy_username'] ?? null,
                'password' => $proxy['proxy_password'] ?? null
            ];
        } elseif ($proxy['proxy_type'] === 'socks5') {
            // SOCKS5 代理配置 - webklex可能不直接支持socks5，需要特殊处理
            $proxyUrl = 'tcp://' . $proxy['proxy_host'] . ':' . $proxy['proxy_port'];
            
            $proxyConfig = [
                'socket' => $proxyUrl,
                'request_fulluri' => false,
                'username' => $proxy['proxy_username'] ?? null,
                'password' => $proxy['proxy_password'] ?? null
            ];
        }
        
        return $proxyConfig;
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
            
            // 获取邮件数量
            $messageCount = $folder->examine();
            
            if ($messageCount == 0) {
                return [
                    'success' => true,
                    'message' => '邮箱中没有邮件',
                    'mail' => null
                ];
            }
            
            // 获取最新的邮件
            $messages = $folder->query()->all()->limit(1, 1)->get();
            
            if ($messages->count() === 0) {
                return [
                    'success' => true,
                    'message' => '邮箱中没有邮件',
                    'mail' => null
                ];
            }
            
            $message = $messages->first();
            
            // 提取邮件信息
            $mailData = [
                'subject' => $this->decodeHeader($message->getSubject()[0] ?? ''),
                'from' => $this->extractFromInfo($message),
                'from_email' => $this->extractFromEmail($message),
                'from_name' => $this->extractFromName($message),
                'to' => $this->extractToEmail($message),
                'date' => $this->formatDate($message->getDate()[0] ?? ''),
                'body' => $this->extractBody($message),
                'message_id' => $message->getMessageId()[0] ?? '',
                'size' => $message->getSize() ?? 0
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
     * 提取发件人信息
     */
    private function extractFromInfo($message) {
        try {
            $from = $message->getFrom()[0] ?? null;
            if ($from) {
                $email = $from->mail ?? '';
                $name = $from->personal ?? '';
                
                if ($name && $name !== $email) {
                    return $name . ' <' . $email . '>';
                } else {
                    return $email;
                }
            }
            return '未知';
        } catch (Exception $e) {
            return '未知';
        }
    }
    
    /**
     * 提取发件人邮箱
     */
    private function extractFromEmail($message) {
        try {
            $from = $message->getFrom()[0] ?? null;
            return $from ? ($from->mail ?? '未知') : '未知';
        } catch (Exception $e) {
            return '未知';
        }
    }
    
    /**
     * 提取发件人姓名
     */
    private function extractFromName($message) {
        try {
            $from = $message->getFrom()[0] ?? null;
            return $from ? ($from->personal ?? '') : '';
        } catch (Exception $e) {
            return '';
        }
    }
    
    /**
     * 提取收件人邮箱
     */
    private function extractToEmail($message) {
        try {
            $to = $message->getTo()[0] ?? null;
            return $to ? ($to->mail ?? '未知') : '未知';
        } catch (Exception $e) {
            return '未知';
        }
    }
    
    /**
     * 提取邮件正文
     */
    private function extractBody($message) {
        try {
            // 尝试获取纯文本内容
            if ($message->hasTextBody()) {
                $body = $message->getTextBody();
            } elseif ($message->hasHTMLBody()) {
                // 如果没有纯文本，获取HTML并转换
                $htmlBody = $message->getHTMLBody();
                $body = $this->htmlToText($htmlBody);
            } else {
                $body = '无法读取邮件内容';
            }
            
            return $this->formatTextContent($body);
        } catch (Exception $e) {
            return '读取邮件内容时出错: ' . $e->getMessage();
        }
    }
    
    /**
     * 解码邮件头信息
     */
    private function decodeHeader($text) {
        if (empty($text)) {
            return '';
        }
        
        // webklex/php-imap 应该已经处理了编码，但我们可以做额外的清理
        return trim($text);
    }
    
    /**
     * 格式化日期
     */
    private function formatDate($date) {
        try {
            if (empty($date)) {
                return '';
            }
            
            $timestamp = is_numeric($date) ? $date : strtotime($date);
            return $timestamp ? date('Y-m-d H:i:s', $timestamp) : $date;
        } catch (Exception $e) {
            return $date;
        }
    }
    
    /**
     * 格式化纯文本内容
     */
    private function formatTextContent($text) {
        // 清理内容
        $text = trim($text);
        
        // 移除多余的空行
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // 移除行首行尾空白但保留缩进
        $lines = explode("\n", $text);
        $formattedLines = [];
        
        foreach ($lines as $line) {
            $formattedLines[] = rtrim($line);
        }
        
        $text = implode("\n", $formattedLines);
        
        // 清理特殊字符和乱码
        $text = preg_replace('/[^\x{20}-\x{7E}\x{4E00}-\x{9FFF}\s]/u', '', $text);
        
        return trim($text);
    }
    
    /**
     * 将HTML内容转换为格式化的纯文本
     */
    private function htmlToText($html) {
        // 清理和预处理HTML
        $html = trim($html);
        
        // 移除脚本和样式标签
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        
        // 处理换行
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
                // 忽略断开连接时的错误
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
                        'connection_method' => $this->useProxy ? '通过代理连接' : '直接连接',
                        'proxy_info' => $this->currentProxy ? [
                            'type' => $this->currentProxy['proxy_type'],
                            'host' => $this->currentProxy['proxy_host'],
                            'port' => $this->currentProxy['proxy_port'],
                            'name' => $this->currentProxy['proxy_name'] ?? 'Unknown'
                        ] : null,
                        'server_info' => $this->server . ':' . $this->port,
                        'protocol' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                        'library' => 'webklex/php-imap (支持代理)'
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '❌ 邮箱连接测试失败',
                    'diagnostics' => [
                        'connection_method' => $this->useProxy ? '代理连接尝试失败，直连也失败' : '直接连接失败',
                        'proxy_available' => $this->proxyManager->getAvailableProxy('', false) !== null,
                        'suggestion' => '请检查服务器地址、端口和网络连接'
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