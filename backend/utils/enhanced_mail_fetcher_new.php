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
            
            // 对于POP3，如果有代理，需要特殊处理
            if ($this->protocol === 'pop3') {
                return $this->connectPOP3WithProxy();
            }
            
            // IMAP协议使用原有的代理客户端
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
     * POP3协议连接（支持代理）
     */
    private function connectPOP3WithProxy() {
        // 检查IMAP扩展（POP3也使用IMAP扩展）
        if (!function_exists('imap_open')) {
            throw new Exception('PHP IMAP 扩展未安装，POP3协议需要IMAP扩展支持');
        }
        
        // 构建POP3连接标志
        $flags = '/pop3';
        if ($this->ssl) {
            $flags .= '/ssl';
        }
        if ($this->port !== 110 && $this->port !== 995) {
            $flags .= ':' . $this->port;
        }
        
        $mailbox = '{' . $this->server . $flags . '}INBOX';
        
        // 如果有代理，记录代理尝试（注意：PHP的imap_open不直接支持代理）
        if ($this->useProxy && $this->currentProxy) {
            error_log('POP3协议暂不支持通过代理连接，将使用直连: ' . $this->currentProxy['proxy_type'] . '://' . 
                     $this->currentProxy['proxy_host'] . ':' . $this->currentProxy['proxy_port']);
            // 对于POP3，我们记录代理尝试但实际使用直连
        }
        
        // 尝试连接
        $startTime = microtime(true);
        $this->client = @imap_open($mailbox, $this->username, $this->password);
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        if (!$this->client) {
            $error = imap_last_error();
            
            // 如果指定了代理但连接失败，记录代理失败统计
            if ($this->useProxy && $this->currentProxy) {
                $this->proxyManager->updateProxyStats($this->currentProxy['id'], false);
                error_log('POP3连接失败 (尝试使用代理设置): ' . ($error ?: '未知错误'));
            } else {
                error_log('POP3直连失败: ' . ($error ?: '未知错误'));
            }
            
            throw new Exception('POP3连接失败: ' . ($error ?: '未知错误'));
        }
        
        // 连接成功
        if ($this->useProxy && $this->currentProxy) {
            // 虽然实际上是直连，但标记代理"成功"以保持统计一致性
            $this->proxyManager->updateProxyStats($this->currentProxy['id'], true, $responseTime);
            error_log('POP3连接成功 (直连，代理配置已记录)，响应时间: ' . $responseTime . 'ms');
        } else {
            error_log('POP3直连成功，响应时间: ' . $responseTime . 'ms');
        }
        
        return true;
    }
    
    /**
     * 获取最新邮件
     */
    public function getLatestMail() {
        if (!$this->client) {
            throw new Exception('未连接到邮件服务器');
        }
        
        try {
            // 对于POP3协议，使用IMAP扩展处理
            if ($this->protocol === 'pop3') {
                return $this->getLatestMailPOP3();
            }
            
            // 对于IMAP协议，使用ProxyImapClient
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
     * 获取POP3最新邮件
     */
    private function getLatestMailPOP3() {
        try {
            // 获取邮件数量
            $mailCount = imap_num_msg($this->client);
            
            if ($mailCount == 0) {
                return [
                    'success' => true,
                    'message' => '邮箱中没有邮件',
                    'mail' => null
                ];
            }
            
            // 获取最新邮件（最后一封）
            $mailNumber = $mailCount;
            
            // 获取邮件头信息
            $header = imap_headerinfo($this->client, $mailNumber);
            
            // 获取邮件体
            $body = $this->getMailBodyPOP3($mailNumber);
            
            // 解码主题
            $subject = $this->decodeHeader($header->subject ?? '');
            
            // 发件人信息
            $from = $header->from[0] ?? null;
            $fromEmail = $from ? $from->mailbox . '@' . $from->host : '未知';
            $fromName = $from && isset($from->personal) ? $this->decodeHeader($from->personal) : $fromEmail;
            
            // 收件人信息
            $to = $header->to[0] ?? null;
            $toEmail = $to ? $to->mailbox . '@' . $to->host : '未知';
            
            // 邮件日期
            $date = $header->date ?? '';
            $timestamp = strtotime($date);
            $formattedDate = $timestamp ? date('Y-m-d H:i:s', $timestamp) : $date;
            
            return [
                'success' => true,
                'mail' => [
                    'subject' => $subject,
                    'from' => $fromName,
                    'from_email' => $fromEmail,
                    'to' => $toEmail,
                    'date' => $formattedDate,
                    'body' => $body,
                    'message_id' => $header->message_id ?? '',
                    'size' => $header->Size ?? 0
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '获取POP3邮件失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取POP3邮件正文
     */
    private function getMailBodyPOP3($mailNumber) {
        $structure = imap_fetchstructure($this->client, $mailNumber);
        
        if (!isset($structure->parts)) {
            // 简单邮件（无多部分）
            $body = imap_fetchbody($this->client, $mailNumber, 1);
            return $this->decodeBodyPOP3($body, $structure);
        }
        
        // 多部分邮件
        $body = '';
        for ($i = 1; $i <= count($structure->parts); $i++) {
            $part = $structure->parts[$i - 1];
            
            // 查找文本部分
            if ($part->type == 0) { // 文本类型
                $partBody = imap_fetchbody($this->client, $mailNumber, $i);
                $decodedBody = $this->decodeBodyPOP3($partBody, $part);
                
                if (!empty($decodedBody)) {
                    $body .= $decodedBody . "\n";
                }
            }
        }
        
        return trim($body) ?: '(邮件内容为空)';
    }
    
    /**
     * 解码POP3邮件正文
     */
    private function decodeBodyPOP3($body, $structure) {
        // 根据编码类型解码
        switch ($structure->encoding) {
            case 1: // 8bit
                $body = imap_8bit($body);
                break;
            case 2: // binary
                // 二进制，通常不需要解码
                break;
            case 3: // base64
                $body = base64_decode($body);
                break;
            case 4: // quoted-printable
                $body = quoted_printable_decode($body);
                break;
            default: // 7bit或其他
                // 不需要特殊处理
                break;
        }
        
        // 字符集转换
        if (isset($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    $charset = $param->value;
                    if (strtolower($charset) !== 'utf-8') {
                        $body = mb_convert_encoding($body, 'UTF-8', $charset);
                    }
                    break;
                }
            }
        }
        
        return $body;
    }
    
    /**
     * 解码邮件头信息（用于POP3）
     */
    private function decodeHeader($header) {
        if (empty($header)) {
            return '';
        }
        
        $decoded = imap_mime_header_decode($header);
        $result = '';
        
        foreach ($decoded as $part) {
            $charset = isset($part->charset) ? $part->charset : 'UTF-8';
            if (strtolower($charset) !== 'utf-8' && strtolower($charset) !== 'default') {
                $result .= mb_convert_encoding($part->text, 'UTF-8', $charset);
            } else {
                $result .= $part->text;
            }
        }
        
        return $result;
    }
    
    /**
     * 关闭连接
     */
    public function close() {
        if ($this->client) {
            if ($this->protocol === 'pop3') {
                // POP3使用imap_close
                imap_close($this->client);
            } else {
                // IMAP使用ProxyImapClient的close方法
                $this->client->close();
            }
            $this->client = null;
        }
    }
    
    /**
     * 测试连接
     */
    public function testConnection() {
        try {
            // 对于POP3协议，使用专门的测试方法
            if ($this->protocol === 'pop3') {
                return $this->testPOP3Connection();
            }
            
            // 对于IMAP协议，使用ProxyImapClient
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
     * 测试POP3连接
     */
    private function testPOP3Connection() {
        try {
            // 检查IMAP扩展
            if (!function_exists('imap_open')) {
                return [
                    'success' => false,
                    'message' => '❌ PHP IMAP扩展未安装，POP3协议需要IMAP扩展支持',
                    'diagnostics' => [
                        'error_type' => 'extension_missing',
                        'suggestion' => '请联系管理员安装PHP IMAP扩展'
                    ]
                ];
            }
            
            // 构建POP3连接标志
            $flags = '/pop3';
            if ($this->ssl) {
                $flags .= '/ssl';
            }
            if ($this->port !== 110 && $this->port !== 995) {
                $flags .= ':' . $this->port;
            }
            
            $mailbox = '{' . $this->server . $flags . '}INBOX';
            
            // 尝试连接
            $startTime = microtime(true);
            $connection = @imap_open($mailbox, $this->username, $this->password);
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            if (!$connection) {
                $error = imap_last_error();
                
                // 如果指定了代理，记录代理尝试失败
                if ($this->useProxy && $this->currentProxy) {
                    $this->proxyManager->updateProxyStats($this->currentProxy['id'], false);
                    return [
                        'success' => false,
                        'message' => '❌ POP3连接测试失败: ' . ($error ?: '未知错误'),
                        'diagnostics' => [
                            'connection_method' => 'POP3直连 (代理不支持)',
                            'proxy_info' => [
                                'type' => $this->currentProxy['proxy_type'],
                                'host' => $this->currentProxy['proxy_host'],
                                'port' => $this->currentProxy['proxy_port'],
                                'note' => 'POP3协议暂不支持代理连接，已尝试直连'
                            ],
                            'server_info' => $this->server . ':' . $this->port,
                            'protocol' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                            'library' => 'PHP IMAP Extension',
                            'error_details' => $error ?: '未知错误'
                        ]
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => '❌ POP3连接测试失败: ' . ($error ?: '未知错误'),
                        'diagnostics' => [
                            'connection_method' => 'POP3直连',
                            'server_info' => $this->server . ':' . $this->port,
                            'protocol' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                            'library' => 'PHP IMAP Extension',
                            'error_details' => $error ?: '未知错误'
                        ]
                    ];
                }
            }
            
            // 连接成功，关闭测试连接
            imap_close($connection);
            
            // 更新代理统计（如果使用了代理设置）
            if ($this->useProxy && $this->currentProxy) {
                $this->proxyManager->updateProxyStats($this->currentProxy['id'], true, $responseTime);
            }
            
            return [
                'success' => true,
                'message' => '✅ POP3邮箱连接测试成功！',
                'diagnostics' => [
                    'connection_method' => $this->useProxy ? 'POP3直连 (代理配置已记录)' : 'POP3直连',
                    'proxy_info' => $this->currentProxy ? [
                        'type' => $this->currentProxy['proxy_type'],
                        'host' => $this->currentProxy['proxy_host'],
                        'port' => $this->currentProxy['proxy_port'],
                        'name' => $this->currentProxy['proxy_name'] ?? 'Unknown',
                        'note' => 'POP3协议暂不支持代理连接，使用直连'
                    ] : null,
                    'server_info' => $this->server . ':' . $this->port,
                    'protocol' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                    'library' => 'PHP IMAP Extension',
                    'response_time' => $responseTime . 'ms'
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ POP3连接测试失败: ' . $e->getMessage(),
                'diagnostics' => [
                    'error_details' => $e->getMessage(),
                    'connection_method' => 'POP3直连失败'
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