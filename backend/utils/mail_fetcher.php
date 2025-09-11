<?php
/**
 * 邮件获取工具类
 * 支持IMAP和POP3协议，支持SSL连接
 * 简化版本，专注基本功能
 */

require_once __DIR__ . '/simple_mail_fetcher.php';

class MailFetcher {
    private $simpleFetcher;
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        // 使用简化的邮件获取器
        $this->simpleFetcher = new SimpleMailFetcher($server, $port, $username, $password, $protocol, $ssl);
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        return $this->simpleFetcher->connect();
    }
    
    /**
     * 获取最新邮件
     */
    public function getLatestMail() {
        return $this->simpleFetcher->getLatestMail();
    }
    
    /**
     * 关闭连接
     */
    public function close() {
        $this->simpleFetcher->close();
    }
    
    /**
     * 测试连接并提供详细诊断信息
     */
    public function testConnection() {
        return $this->simpleFetcher->testConnection();
    }
}
?>