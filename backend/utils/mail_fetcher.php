<?php
/**
 * 邮件获取工具类
 * 支持IMAP和POP3协议，支持SSL连接
 * 简化版本，移除复杂的代理实现
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
        if (!function_exists('imap_open')) {
            throw new Exception('PHP IMAP 扩展未安装');
        }
        
        $flags = '/imap';
        if ($this->ssl) {
            $flags .= '/ssl';
        }
        if ($this->port !== 143 && $this->port !== 993) {
            $flags .= ':' . $this->port;
        }
        
        $mailbox = '{' . $this->server . $flags . '}INBOX';
        
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
        if ($this->port !== 110 && $this->port !== 995) {
            $flags .= ':' . $this->port;
        }
        
        $mailbox = '{' . $this->server . $flags . '}INBOX';
        
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
            
            if ($mailCount === 0) {
                return [
                    'success' => true,
                    'message' => '邮箱中没有邮件',
                    'mail' => null
                ];
            }
            
            // 获取最新邮件（最后一封）
            $latestMsgNum = $mailCount;
            
            // 获取邮件头信息
            $headers = imap_fetchstructure($this->connection, $latestMsgNum);
            $headerInfo = imap_headerinfo($this->connection, $latestMsgNum);
            
            // 解析邮件信息
            $mail = [
                'subject' => $this->decodeHeader($headerInfo->subject ?? 'No Subject'),
                'from' => $this->decodeHeader($headerInfo->fromaddress ?? 'Unknown'),
                'from_email' => $headerInfo->from[0]->mailbox . '@' . $headerInfo->from[0]->host ?? 'unknown@unknown.com',
                'from_name' => $this->decodeHeader($headerInfo->from[0]->personal ?? 'Unknown'),
                'to' => $this->decodeHeader($headerInfo->toaddress ?? 'Unknown'),
                'date' => date('Y-m-d H:i:s', $headerInfo->udate ?? time()),
                'body' => $this->getMailBody($latestMsgNum, $headers),
                'message_id' => $headerInfo->message_id ?? '<unknown@unknown.com>',
                'size' => $headerInfo->Size ?? 0
            ];
            
            return [
                'success' => true,
                'mail' => $mail
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '获取邮件失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取邮件内容
     */
    private function getMailBody($msgNum, $structure) {
        $body = '';
        
        if (empty($structure->parts)) {
            // 单一部分邮件
            $body = imap_fetchbody($this->connection, $msgNum, 1);
            
            // 解码内容
            if ($structure->encoding == 3) { // Base64
                $body = base64_decode($body);
            } elseif ($structure->encoding == 4) { // Quoted-printable  
                $body = quoted_printable_decode($body);
            }
        } else {
            // 多部分邮件，找到文本部分
            foreach ($structure->parts as $partNum => $part) {
                if ($part->subtype == 'PLAIN') {
                    $body = imap_fetchbody($this->connection, $msgNum, $partNum + 1);
                    
                    if ($part->encoding == 3) { // Base64
                        $body = base64_decode($body);
                    } elseif ($part->encoding == 4) { // Quoted-printable
                        $body = quoted_printable_decode($body);
                    }
                    break;
                }
            }
        }
        
        // 如果没有找到纯文本，尝试获取HTML并转换
        if (empty($body) && !empty($structure->parts)) {
            foreach ($structure->parts as $partNum => $part) {
                if ($part->subtype == 'HTML') {
                    $body = imap_fetchbody($this->connection, $msgNum, $partNum + 1);
                    
                    if ($part->encoding == 3) { // Base64
                        $body = base64_decode($body);
                    } elseif ($part->encoding == 4) { // Quoted-printable
                        $body = quoted_printable_decode($body);
                    }
                    
                    // 简单的HTML到文本转换
                    $body = strip_tags($body);
                    break;
                }
            }
        }
        
        return trim($body) ?: '(邮件内容为空)';
    }
    
    /**
     * 解码邮件头
     */
    private function decodeHeader($header) {
        if (empty($header)) {
            return 'Unknown';
        }
        
        $decoded = imap_mime_header_decode($header);
        $result = '';
        
        foreach ($decoded as $element) {
            $result .= $element->text;
        }
        
        return trim($result) ?: 'Unknown';
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
    }
    
    /**
     * 检查是否尝试过使用代理
     */
    public function wasProxyAttempted() {
        return $this->enhancedFetcher->wasProxyAttempted();
    }
    
    /**
     * 设置是否使用代理
     */
    public function setUseProxy($useProxy) {
        $this->enhancedFetcher->setUseProxy($useProxy);
    }
}
?>