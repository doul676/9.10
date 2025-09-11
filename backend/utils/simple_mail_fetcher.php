<?php
/**
 * 简化的邮件获取工具类
 * 支持IMAP和POP3协议，支持SSL连接
 * 基于原始代码要求，专注核心功能
 */

class SimpleMailFetcher {
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
        if (!function_exists('imap_open')) {
            throw new Exception('PHP IMAP 扩展未安装');
        }
        
        $flags = '/imap';
        if ($this->ssl) {
            $flags .= '/ssl';
        }
        
        // 不强制端口检查，让用户配置决定
        if ($this->port && $this->port !== 143 && $this->port !== 993) {
            $flags .= ':' . $this->port;
        }
        
        $mailbox = '{' . $this->server . $flags . '}INBOX';
        
        // 添加错误抑制并清除之前的错误
        imap_errors();
        $this->connection = @imap_open($mailbox, $this->username, $this->password);
        
        if (!$this->connection) {
            $error = imap_last_error();
            throw new Exception('IMAP连接失败: ' . ($error ?: '未知错误'));
        }
        
        return true;
    }
    
    /**
     * POP3连接
     */
    private function connectPOP3() {
        $flags = '/pop3';
        if ($this->ssl) {
            $flags .= '/ssl';
        }
        
        if ($this->port && $this->port !== 110 && $this->port !== 995) {
            $flags .= ':' . $this->port;
        }
        
        $mailbox = '{' . $this->server . $flags . '}INBOX';
        
        // 添加错误抑制并清除之前的错误
        imap_errors();
        $this->connection = @imap_open($mailbox, $this->username, $this->password);
        
        if (!$this->connection) {
            $error = imap_last_error();
            throw new Exception('POP3连接失败: ' . ($error ?: '未知错误'));
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
        
        if (isset($structure->parts) && count($structure->parts)) {
            // 多部分邮件
            $body = '';
            for ($i = 0; $i < count($structure->parts); $i++) {
                $part = $structure->parts[$i];
                $partNumber = $i + 1;
                
                if ($part->type === 0) { // TEXT
                    $partBody = imap_fetchbody($this->connection, $mailNumber, $partNumber);
                    
                    // 解码
                    if ($part->encoding === 3) { // BASE64
                        $partBody = base64_decode($partBody);
                    } elseif ($part->encoding === 4) { // QUOTED-PRINTABLE
                        $partBody = quoted_printable_decode($partBody);
                    }
                    
                    $body .= $partBody;
                }
            }
            return $body ?: imap_body($this->connection, $mailNumber);
        } else {
            // 单部分邮件
            $body = imap_body($this->connection, $mailNumber);
            
            if ($structure->encoding === 3) { // BASE64
                $body = base64_decode($body);
            } elseif ($structure->encoding === 4) { // QUOTED-PRINTABLE
                $body = quoted_printable_decode($body);
            }
            
            return $body;
        }
    }
    
    /**
     * 解码邮件头信息
     */
    private function decodeHeader($text) {
        if (empty($text)) {
            return '';
        }
        
        $decoded = imap_mime_header_decode($text);
        $result = '';
        
        foreach ($decoded as $element) {
            if ($element->charset !== 'default') {
                $result .= mb_convert_encoding($element->text, 'UTF-8', $element->charset);
            } else {
                $result .= $element->text;
            }
        }
        
        return $result;
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