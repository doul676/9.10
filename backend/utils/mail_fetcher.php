<?php
/**
 * 邮件获取工具类
 * 支持IMAP和POP3协议，支持SSL连接
 */

class MailFetcher {
    private $server;
    private $port;
    private $username;
    private $password;
    private $protocol;
    private $ssl;
    private $connection;
    private $proxyEnabled = false;
    private $proxyInfo = null;
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->protocol = strtolower($protocol);
        $this->ssl = $ssl;
        
        // 检查是否启用了代理
        $this->checkProxyStatus();
    }
    
    /**
     * 检查代理状态并配置代理信息
     */
    private function checkProxyStatus() {
        try {
            $db = new SQLite3(__DIR__ . '/../../db/mail.sqlite');
            
            // 获取代理配置
            $result = $db->query("SELECT config_key, config_value FROM proxy_config WHERE config_key IN ('proxy_enabled', 'active_proxy_type', 'active_proxy_id')");
            $config = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $config[$row['config_key']] = $row['config_value'];
            }
            
            // 检查是否启用代理
            if (isset($config['proxy_enabled']) && $config['proxy_enabled'] === '1') {
                $proxyType = $config['active_proxy_type'] ?? '';
                $proxyId = (int)($config['active_proxy_id'] ?? 0);
                
                if (!empty($proxyType) && $proxyId > 0) {
                    // 获取代理详情
                    $tableName = $proxyType === 'socks5' ? 'socks5_proxies' : 'http_proxies';
                    $stmt = $db->prepare("SELECT * FROM {$tableName} WHERE id = ? AND status = 1");
                    $stmt->bindValue(1, $proxyId);
                    $proxyResult = $stmt->execute();
                    $proxy = $proxyResult->fetchArray(SQLITE3_ASSOC);
                    
                    if ($proxy) {
                        $this->proxyEnabled = true;
                        $this->proxyInfo = [
                            'type' => $proxyType,
                            'host' => $proxy['host'],
                            'port' => $proxy['port'],
                            'username' => $proxy['username'] ?? '',
                            'password' => $proxy['password'] ?? '',
                            'name' => $proxy['name'] ?? ''
                        ];
                        
                        // 设置代理环境变量（用于支持代理的库）
                        $this->setProxyEnvironment();
                    }
                }
            }
            
            $db->close();
        } catch (Exception $e) {
            error_log('检查代理状态失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 设置代理环境变量
     */
    private function setProxyEnvironment() {
        if (!$this->proxyEnabled || !$this->proxyInfo) {
            return;
        }
        
        $proxyUrl = '';
        if ($this->proxyInfo['type'] === 'http') {
            if (!empty($this->proxyInfo['username']) && !empty($this->proxyInfo['password'])) {
                $proxyUrl = "http://{$this->proxyInfo['username']}:{$this->proxyInfo['password']}@{$this->proxyInfo['host']}:{$this->proxyInfo['port']}";
            } else {
                $proxyUrl = "http://{$this->proxyInfo['host']}:{$this->proxyInfo['port']}";
            }
        } else if ($this->proxyInfo['type'] === 'socks5') {
            if (!empty($this->proxyInfo['username']) && !empty($this->proxyInfo['password'])) {
                $proxyUrl = "socks5://{$this->proxyInfo['username']}:{$this->proxyInfo['password']}@{$this->proxyInfo['host']}:{$this->proxyInfo['port']}";
            } else {
                $proxyUrl = "socks5://{$this->proxyInfo['host']}:{$this->proxyInfo['port']}";
            }
        }
        
        if ($proxyUrl) {
            // 设置HTTP和HTTPS代理环境变量
            putenv("http_proxy={$proxyUrl}");
            putenv("https_proxy={$proxyUrl}");
            putenv("HTTP_PROXY={$proxyUrl}");
            putenv("HTTPS_PROXY={$proxyUrl}");
        }
    }
    
    /**
     * 获取代理信息
     */
    public function getProxyInfo() {
        return [
            'enabled' => $this->proxyEnabled,
            'info' => $this->proxyInfo
        ];
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        try {
            // 记录代理使用情况
            if ($this->proxyEnabled) {
                error_log("邮件连接使用代理: {$this->proxyInfo['name']} ({$this->proxyInfo['type']}) - {$this->proxyInfo['host']}:{$this->proxyInfo['port']}");
            } else {
                error_log("邮件连接未使用代理");
            }
            
            if ($this->protocol === 'imap') {
                return $this->connectIMAP();
            } elseif ($this->protocol === 'pop3') {
                return $this->connectPOP3();
            } else {
                throw new Exception('不支持的协议: ' . $this->protocol);
            }
        } catch (Exception $e) {
            error_log('邮件连接失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * IMAP连接
     */
    private function connectIMAP() {
        // 更详细的IMAP扩展检查
        if (!extension_loaded('imap')) {
            throw new Exception('PHP IMAP 扩展未加载 (当前环境: ' . php_sapi_name() . ')，请检查Web服务器PHP配置');
        }
        
        if (!function_exists('imap_open')) {
            throw new Exception('PHP IMAP 扩展的 imap_open 函数不可用，可能是扩展安装不完整');
        }
        
        $flags = '/imap';
        if ($this->ssl) {
            $flags .= '/ssl';
        }
        
        // 构建邮箱连接字符串
        $mailbox = '{' . $this->server . ':' . $this->port . $flags . '}INBOX';
        
        // 尝试连接，禁用SSL验证以避免证书问题
        if ($this->ssl) {
            $mailbox = '{' . $this->server . ':' . $this->port . '/imap/ssl/novalidate-cert}INBOX';
        }
        
        // 清除之前的IMAP错误
        imap_errors();
        
        $this->connection = @imap_open($mailbox, $this->username, $this->password);
        
        if (!$this->connection) {
            $errors = imap_errors();
            $lastError = imap_last_error();
            
            // 提供更具体的错误信息
            if ($lastError) {
                if (strpos($lastError, 'Certificate failure') !== false) {
                    throw new Exception('SSL证书验证失败，请检查服务器SSL配置或尝试关闭SSL');
                } elseif (strpos($lastError, 'Connection refused') !== false) {
                    throw new Exception('连接被拒绝，请检查服务器地址和端口是否正确');
                } elseif (strpos($lastError, 'Login failed') !== false || strpos($lastError, 'Authentication failed') !== false) {
                    throw new Exception('邮箱用户名或密码错误，请检查登录凭据');
                } elseif (strpos($lastError, 'host not found') !== false || strpos($lastError, 'Unknown host') !== false) {
                    throw new Exception('服务器地址无法解析，请检查服务器地址是否正确');
                } else {
                    throw new Exception('IMAP连接失败: ' . $lastError);
                }
            } else {
                throw new Exception('IMAP连接失败: 未知错误，请检查网络连接和服务器配置');
            }
        }
        
        return true;
    }
    
    /**
     * POP3连接
     */
    private function connectPOP3() {
        // 更详细的IMAP扩展检查（POP3也使用IMAP扩展）
        if (!extension_loaded('imap')) {
            throw new Exception('PHP IMAP 扩展未加载 (当前环境: ' . php_sapi_name() . ')，请检查Web服务器PHP配置');
        }
        
        if (!function_exists('imap_open')) {
            throw new Exception('PHP IMAP 扩展的 imap_open 函数不可用，可能是扩展安装不完整');
        }
        
        $flags = '/pop3';
        if ($this->ssl) {
            $flags .= '/ssl';
        }
        
        // 构建邮箱连接字符串
        $mailbox = '{' . $this->server . ':' . $this->port . $flags . '}INBOX';
        
        // 尝试连接，禁用SSL验证以避免证书问题
        if ($this->ssl) {
            $mailbox = '{' . $this->server . ':' . $this->port . '/pop3/ssl/novalidate-cert}INBOX';
        }
        
        // 清除之前的IMAP错误
        imap_errors();
        
        $this->connection = @imap_open($mailbox, $this->username, $this->password);
        
        if (!$this->connection) {
            $errors = imap_errors();
            $lastError = imap_last_error();
            
            // 提供更具体的错误信息
            if ($lastError) {
                if (strpos($lastError, 'Certificate failure') !== false) {
                    throw new Exception('SSL证书验证失败，请检查服务器SSL配置或尝试关闭SSL');
                } elseif (strpos($lastError, 'Connection refused') !== false) {
                    throw new Exception('连接被拒绝，请检查服务器地址和端口是否正确');
                } elseif (strpos($lastError, 'Login failed') !== false || strpos($lastError, 'Authentication failed') !== false) {
                    throw new Exception('邮箱用户名或密码错误，请检查登录凭据');
                } elseif (strpos($lastError, 'host not found') !== false || strpos($lastError, 'Unknown host') !== false) {
                    throw new Exception('服务器地址无法解析，请检查服务器地址是否正确');
                } else {
                    throw new Exception('POP3连接失败: ' . $lastError);
                }
            } else {
                throw new Exception('POP3连接失败: 未知错误，请检查网络连接和服务器配置');
            }
        }
        
        return true;
    }
    
    /**
     * 获取最新邮件
     */
    public function getLatestMail() {
        if (!$this->connection) {
            throw new Exception('未连接到邮件服务器');
        }
        
        try {
            // 获取邮件数量
            $mailCount = imap_num_msg($this->connection);
            
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
            $header = imap_headerinfo($this->connection, $mailNumber);
            
            // 获取邮件体
            $bodyInfo = $this->getMailBody($mailNumber);
            
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
            
            $mailData = [
                'subject' => $subject,
                'from' => $fromName,
                'from_email' => $fromEmail,
                'to' => $toEmail,
                'date' => $formattedDate,
                'message_id' => $header->message_id ?? '',
                'size' => $header->Size ?? 0
            ];
            
            // 处理邮件内容
            if (is_array($bodyInfo)) {
                $mailData['body_type'] = $bodyInfo['type'];
                $mailData['body'] = $bodyInfo['content'];
                $mailData['images'] = $bodyInfo['images'] ?? [];
                $mailData['attachments'] = $bodyInfo['attachments'] ?? [];
            } else {
                // 兼容旧版本返回格式
                $mailData['body_type'] = 'text';
                $mailData['body'] = $bodyInfo;
                $mailData['images'] = [];
                $mailData['attachments'] = [];
            }
            
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
     * 获取邮件正文和附件信息
     */
    private function getMailBody($mailNumber) {
        $structure = imap_fetchstructure($this->connection, $mailNumber);
        
        if (!isset($structure->parts)) {
            // 简单邮件（无多部分）
            $body = imap_fetchbody($this->connection, $mailNumber, 1);
            $decodedBody = $this->decodeBody($body, $structure);
            
            // 检查是否是图片内容
            if ($structure->type == 5) { // IMAGE type
                return [
                    'type' => 'image',
                    'content' => $decodedBody,
                    'subtype' => strtolower($structure->subtype ?? ''),
                    'encoding' => $structure->encoding ?? 0
                ];
            }
            
            return [
                'type' => 'text',
                'content' => $decodedBody
            ];
        }
        
        // 多部分邮件
        $textBody = '';
        $htmlBody = '';
        $images = [];
        $attachments = [];
        
        $this->parseMailParts($structure->parts, $mailNumber, '', $textBody, $htmlBody, $images, $attachments);
        
        // 如果有HTML内容，优先使用HTML，否则使用文本
        $mainContent = $htmlBody ?: $textBody;
        
        // 如果没有文本内容但有图片，显示图片
        if (empty($mainContent) && !empty($images)) {
            return [
                'type' => 'image',
                'content' => '',
                'images' => $images,
                'attachments' => $attachments
            ];
        }
        
        return [
            'type' => !empty($htmlBody) ? 'html' : 'text',
            'content' => $mainContent ?: '无法读取邮件内容',
            'images' => $images,
            'attachments' => $attachments
        ];
    }
    
    /**
     * 递归解析邮件各部分
     */
    private function parseMailParts($parts, $mailNumber, $prefix, &$textBody, &$htmlBody, &$images, &$attachments) {
        foreach ($parts as $partIndex => $part) {
            $partNumber = $prefix . ($partIndex + 1);
            
            if (isset($part->parts)) {
                // 嵌套部分，递归处理
                $this->parseMailParts($part->parts, $mailNumber, $partNumber . '.', $textBody, $htmlBody, $images, $attachments);
            } else {
                // 处理具体内容部分
                $this->procesMailPart($part, $mailNumber, $partNumber, $textBody, $htmlBody, $images, $attachments);
            }
        }
    }
    
    /**
     * 处理单个邮件部分
     */
    private function procesMailPart($part, $mailNumber, $partNumber, &$textBody, &$htmlBody, &$images, &$attachments) {
        // 获取MIME类型
        $mimeType = $this->getMimeType($part);
        $disposition = $this->getDisposition($part);
        $filename = $this->getFilename($part);
        
        // 获取部分内容
        $partBody = imap_fetchbody($this->connection, $mailNumber, $partNumber);
        $decodedBody = $this->decodeBody($partBody, $part);
        
        // 根据类型和disposition处理
        if ($part->type == 0) { // TEXT
            if (strtolower($part->subtype) == 'plain' && empty($textBody)) {
                $textBody = $decodedBody;
            } elseif (strtolower($part->subtype) == 'html' && empty($htmlBody)) {
                $htmlBody = $decodedBody;
            }
        } elseif ($part->type == 5) { // IMAGE
            $imageInfo = [
                'filename' => $filename ?: 'image_' . $partNumber . '.' . strtolower($part->subtype),
                'mime_type' => $mimeType,
                'size' => $part->bytes ?? strlen($decodedBody),
                'content' => base64_encode($decodedBody),
                'part_number' => $partNumber,
                'subtype' => strtolower($part->subtype ?? '')
            ];
            
            if ($disposition == 'inline' || empty($disposition)) {
                $images[] = $imageInfo;
            } else {
                $attachments[] = $imageInfo;
            }
        } else {
            // 其他类型作为附件处理
            if (!empty($filename) || $disposition == 'attachment') {
                $attachments[] = [
                    'filename' => $filename ?: 'attachment_' . $partNumber,
                    'mime_type' => $mimeType,
                    'size' => $part->bytes ?? strlen($decodedBody),
                    'content' => base64_encode($decodedBody),
                    'part_number' => $partNumber,
                    'type' => $this->getTypeDescription($part->type)
                ];
            }
        }
    }
    
    /**
     * 获取MIME类型
     */
    private function getMimeType($part) {
        $primaryTypes = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER'];
        $primaryType = $primaryTypes[$part->type] ?? 'OTHER';
        $subType = $part->subtype ?? '';
        return strtolower($primaryType . '/' . $subType);
    }
    
    /**
     * 获取disposition
     */
    private function getDisposition($part) {
        if (isset($part->disposition)) {
            return strtolower($part->disposition);
        }
        return '';
    }
    
    /**
     * 获取文件名
     */
    private function getFilename($part) {
        $filename = '';
        
        // 从disposition参数中获取文件名
        if (isset($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) == 'filename') {
                    $filename = $param->value;
                    break;
                }
            }
        }
        
        // 从parameters中获取文件名
        if (empty($filename) && isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) == 'name') {
                    $filename = $param->value;
                    break;
                }
            }
        }
        
        return $this->decodeFilename($filename);
    }
    
    /**
     * 解码文件名
     */
    private function decodeFilename($filename) {
        if (empty($filename)) {
            return '';
        }
        
        $decoded = '';
        $elements = imap_mime_header_decode($filename);
        
        foreach ($elements as $element) {
            $charset = $element->charset ?? 'UTF-8';
            $text = $element->text;
            
            if (strtolower($charset) !== 'utf-8' && $charset !== 'default') {
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            }
            
            $decoded .= $text;
        }
        
        return $decoded;
    }
    
    /**
     * 获取类型描述
     */
    private function getTypeDescription($type) {
        $types = [
            0 => 'text',
            1 => 'multipart', 
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            7 => 'other'
        ];
        return $types[$type] ?? 'unknown';
    }
    
    /**
     * 解码邮件正文
     */
    private function decodeBody($body, $structure) {
        // 根据编码类型解码
        switch ($structure->encoding ?? 0) {
            case 1: // 8bit
                break;
            case 2: // Binary
                break;
            case 3: // Base64
                $body = base64_decode($body);
                break;
            case 4: // Quoted-printable
                $body = quoted_printable_decode($body);
                break;
            default:
                break;
        }
        
        // 字符集转换
        $charset = 'UTF-8';
        if (isset($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    $charset = $param->value;
                    break;
                }
            }
        }
        
        if (strtolower($charset) !== 'utf-8') {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }
        
        return $body;
    }
    
    /**
     * 解码邮件头信息
     */
    private function decodeHeader($text) {
        if (empty($text)) {
            return '';
        }
        
        $decoded = '';
        $elements = imap_mime_header_decode($text);
        
        foreach ($elements as $element) {
            $charset = $element->charset ?? 'UTF-8';
            $text = $element->text;
            
            if (strtolower($charset) !== 'utf-8' && $charset !== 'default') {
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            }
            
            $decoded .= $text;
        }
        
        return $decoded;
    }
    
    /**
     * 关闭连接
     */
    public function close() {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }
    
    /**
     * 测试连接并提供详细诊断信息
     */
    public function testConnection() {
        try {
            // 首先检查IMAP扩展状态
            if (!extension_loaded('imap')) {
                $sapi = php_sapi_name();
                return [
                    'success' => false,
                    'message' => '❌ IMAP扩展功能不完整 - 扩展未加载',
                    'diagnostics' => [
                        'extension_status' => '❌ php-imap扩展未在当前环境中加载',
                        'environment' => "当前运行环境: {$sapi}",
                        'suggestion' => '请检查Web服务器PHP配置，确保IMAP扩展已启用',
                        'solution' => '联系管理员在Web服务器环境中安装和启用php-imap扩展'
                    ],
                    'error_type' => 'extension_not_loaded'
                ];
            }
            
            // 检查核心IMAP函数是否可用
            $requiredFunctions = ['imap_open', 'imap_close', 'imap_errors', 'imap_last_error', 'imap_num_msg'];
            $missingFunctions = [];
            
            foreach ($requiredFunctions as $function) {
                if (!function_exists($function)) {
                    $missingFunctions[] = $function;
                }
            }
            
            if (!empty($missingFunctions)) {
                return [
                    'success' => false,
                    'message' => '❌ IMAP扩展功能不完整 - 核心函数缺失',
                    'diagnostics' => [
                        'extension_status' => '✅ php-imap扩展已加载',
                        'function_issue' => '❌ 部分IMAP核心函数不可用: ' . implode(', ', $missingFunctions),
                        'available_functions' => '✅ 可用函数: ' . implode(', ', array_diff($requiredFunctions, $missingFunctions)),
                        'suggestion' => 'IMAP扩展已安装但功能不完整，请重新安装php-imap扩展',
                        'solution' => '联系管理员重新配置或重新编译php-imap扩展'
                    ],
                    'error_type' => 'function_missing'
                ];
            }
            
            // 尝试实际连接测试
            if ($this->connect()) {
                $this->close();
                return [
                    'success' => true,
                    'message' => '✅ 邮箱连接测试成功！',
                    'diagnostics' => [
                        'imap_extension' => '✅ IMAP扩展完全可用',
                        'connection_test' => '✅ 服务器连接成功',
                        'protocol_info' => strtoupper($this->protocol) . ($this->ssl ? ' with SSL/TLS' : ' without SSL'),
                        'server_info' => $this->server . ':' . $this->port,
                        'auth_status' => '✅ 身份验证成功'
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '❌ 邮箱连接测试失败',
                    'diagnostics' => [
                        'imap_extension' => '✅ IMAP扩展完全可用',
                        'connection_issue' => '❌ 无法建立服务器连接',
                        'suggestion' => '请检查服务器地址、端口和网络连接',
                        'troubleshoot' => '验证邮箱服务器配置和防火墙设置'
                    ],
                    'error_type' => 'connection_failed'
                ];
            }
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorType = 'unknown';
            $diagnostics = [
                'imap_extension' => '✅ IMAP扩展完全可用',
                'error_details' => $errorMessage
            ];
            
            // 根据错误信息提供精确的诊断建议
            if (strpos($errorMessage, 'IMAP 扩展未安装') !== false || strpos($errorMessage, 'imap扩展') !== false) {
                $errorType = 'extension_issue';
                $diagnostics['issue_type'] = '❌ IMAP扩展相关问题';
                $diagnostics['suggestion'] = '重新安装或重新配置php-imap扩展';
            } elseif (strpos($errorMessage, 'SSL证书') !== false) {
                $errorType = 'ssl_error';
                $diagnostics['issue_type'] = '❌ SSL证书验证失败';
                $diagnostics['suggestion'] = '检查SSL证书配置或尝试关闭SSL连接';
            } elseif (strpos($errorMessage, '连接被拒绝') !== false) {
                $errorType = 'connection_refused';
                $diagnostics['issue_type'] = '❌ 服务器拒绝连接';
                $diagnostics['suggestion'] = '检查服务器地址、端口和防火墙设置';
            } elseif (strpos($errorMessage, '用户名或密码') !== false || strpos($errorMessage, 'Authentication') !== false) {
                $errorType = 'auth_failed';
                $diagnostics['issue_type'] = '❌ 身份验证失败';
                $diagnostics['suggestion'] = '检查邮箱地址和密码，某些邮箱需要应用专用密码';
            } elseif (strpos($errorMessage, '服务器地址') !== false || strpos($errorMessage, 'host not found') !== false) {
                $errorType = 'host_not_found';
                $diagnostics['issue_type'] = '❌ 服务器地址解析失败';
                $diagnostics['suggestion'] = '检查服务器地址拼写和网络连接';
            } else {
                $diagnostics['issue_type'] = '❌ 未知连接错误';
                $diagnostics['suggestion'] = '查看详细错误信息进行排查';
            }
            
            return [
                'success' => false,
                'message' => '❌ 连接测试失败: ' . $errorMessage,
                'diagnostics' => $diagnostics,
                'error_type' => $errorType
            ];
        }
    }
}
?>