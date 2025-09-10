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
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->protocol = strtolower($protocol);
        $this->ssl = $ssl;
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        try {
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
            $body = $this->getMailBody($mailNumber);
            
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
                'message' => '获取邮件失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取邮件正文
     */
    private function getMailBody($mailNumber) {
        $structure = imap_fetchstructure($this->connection, $mailNumber);
        
        if (!isset($structure->parts)) {
            // 简单邮件（无多部分）
            $body = imap_fetchbody($this->connection, $mailNumber, 1);
            return $this->decodeBody($body, $structure);
        }
        
        // 多部分邮件
        $body = '';
        for ($i = 1; $i <= count($structure->parts); $i++) {
            $part = $structure->parts[$i - 1];
            
            // 查找文本部分
            if ($part->type == 0) { // 文本类型
                $partBody = imap_fetchbody($this->connection, $mailNumber, $i);
                $body .= $this->decodeBody($partBody, $part);
                break; // 只取第一个文本部分
            }
        }
        
        return $body ?: '无法读取邮件内容';
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
     * 测试连接
     */
    public function testConnection() {
        try {
            if ($this->connect()) {
                $this->close();
                return [
                    'success' => true,
                    'message' => '连接成功'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '连接失败'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '连接失败: ' . $e->getMessage()
            ];
        }
    }
}
?>