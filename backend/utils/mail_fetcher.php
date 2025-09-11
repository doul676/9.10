<?php
/**
 * 邮件获取工具类
 * 支持IMAP协议，支持SSL连接，支持真实的HTTP/SOCKS5代理连接
 * 替换webklex/php-imap为自定义代理支持实现
 */

require_once __DIR__ . '/enhanced_mail_fetcher_new.php';

class MailFetcher {
    private $enhancedFetcher;
    
    public function __construct($server, $port, $username, $password, $protocol = 'imap', $ssl = true) {
        // 使用新的增强版邮件获取器
        $this->enhancedFetcher = new EnhancedMailFetcher($server, $port, $username, $password, $protocol, $ssl);
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        return $this->enhancedFetcher->connect();
    }
    
    /**
     * 获取最新邮件
     */
    public function getLatestMail() {
        return $this->enhancedFetcher->getLatestMail();
    }
    
    /**
     * 关闭连接
     */
    public function close() {
        $this->enhancedFetcher->close();
    }
    
    /**
     * 测试连接并提供详细诊断信息
     */
    public function testConnection() {
        return $this->enhancedFetcher->testConnection();
    }
    
    /**
     * 获取当前使用的代理信息
     */
    public function getCurrentProxy() {
        return $this->enhancedFetcher->getCurrentProxy();
    }
    
    /**
     * 设置是否使用代理
     */
    public function setUseProxy($useProxy) {
        $this->enhancedFetcher->setUseProxy($useProxy);
    }
}
?>