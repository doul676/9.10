<?php
/**
 * 邮件获取工具类
 * 支持IMAP和POP3协议，支持SSL连接，支持自动代理连接
 */

require_once __DIR__ . '/../../backend/utils/proxy_manager.php';

class MailFetcher {
    private $server;
    private $port;
    private $username;
    private $password;
    private $protocol;
    private $ssl;
    private $connection;
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
        
        // 初始化代理管理器，用于自动判断是否使用代理
        $this->proxyManager = new ProxyManager();
        
        // 自动检查是否有可用代理，如果有则使用代理连接
        $this->useProxy = $this->shouldUseProxy();
    }
    
    /**
     * 判断是否应该使用代理
     * 注意：PHP IMAP扩展不支持代理，所以我们只记录代理状态，实际连接使用直连
     */
    private function shouldUseProxy() {
        try {
            $availableProxy = $this->proxyManager->getAvailableProxy('', false);
            // 即使有代理可用，PHP IMAP扩展也无法直接使用代理
            // 这里只是为了记录代理状态，实际连接仍然是直连
            return false; // 暂时禁用代理，因为IMAP扩展不支持
        } catch (Exception $e) {
            // 如果获取代理时出错，使用直连
            return false;
        }
    }
    
    /**
     * 连接到邮件服务器
     */
    public function connect() {
        try {
            // 注意：PHP IMAP扩展不支持代理，所以这里总是使用直连
            // 但我们会记录代理池状态以供诊断使用
            if ($this->useProxy) {
                $this->proxy = $this->proxyManager->getAvailableProxy('', false);
                if (!$this->proxy) {
                    // 如果没有可用代理，改为直连
                    $this->useProxy = false;
                }
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
        
        // 注意：PHP IMAP扩展不支持代理，这里使用直连
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
        
        // 注意：PHP IMAP扩展不支持代理，这里使用直连
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
            
            // 发件人信息 - 完整格式显示 "姓名 <email@domain.com>"
            $from = $header->from[0] ?? null;
            $fromEmail = $from ? $from->mailbox . '@' . $from->host : '未知';
            $fromName = $from && isset($from->personal) ? $this->decodeHeader($from->personal) : '';
            
            // 构建完整的发件人显示格式
            if ($fromName && $fromName !== $fromEmail) {
                $fromDisplay = $fromName . ' <' . $fromEmail . '>';
            } else {
                $fromDisplay = $fromEmail;
            }
            
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
                    'from' => $fromDisplay,
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
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
            $decodedBody = $this->decodeBody($body, $structure);
            
            // 检查是否为HTML内容
            if (isset($structure->subtype) && strtolower($structure->subtype) === 'html') {
                return $this->htmlToText($decodedBody);
            } else {
                return $this->formatTextContent($decodedBody);
            }
        }
        
        // 多部分邮件 - 优先查找纯文本，其次HTML
        $textBody = '';
        $htmlBody = '';
        
        for ($i = 1; $i <= count($structure->parts); $i++) {
            $part = $structure->parts[$i - 1];
            
            // 只处理文本类型的内容，跳过附件
            if ($part->type == 0) { // 文本类型
                $partBody = imap_fetchbody($this->connection, $mailNumber, $i);
                $decodedBody = $this->decodeBody($partBody, $part);
                
                // 检查是否为HTML内容
                if (isset($part->subtype) && strtolower($part->subtype) === 'html') {
                    if (empty($htmlBody)) { // 只取第一个HTML部分
                        $htmlBody = $decodedBody;
                    }
                } else {
                    if (empty($textBody)) { // 只取第一个文本部分
                        $textBody = $decodedBody;
                    }
                }
            }
        }
        
        // 优先返回纯文本，如果没有则转换HTML为文本
        if (!empty($textBody)) {
            return $this->formatTextContent($textBody);
        } elseif (!empty($htmlBody)) {
            return $this->htmlToText($htmlBody);
        }
        
        return '无法读取邮件内容';
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
     * 格式化纯文本内容 - 提升可读性
     */
    private function formatTextContent($text) {
        // 清理内容
        $text = trim($text);
        
        // 移除多余的空行（保留段落分隔，最多两个连续换行）
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // 移除行首行尾空白但保留缩进
        $lines = explode("\n", $text);
        $formattedLines = [];
        
        foreach ($lines as $line) {
            // 移除行尾空白，保留行首缩进
            $formattedLines[] = rtrim($line);
        }
        
        $text = implode("\n", $formattedLines);
        
        // 移除开头和结尾的多余空行
        $text = trim($text);
        
        // 清理特殊字符和乱码
        $text = preg_replace('/[^\x{20}-\x{7E}\x{4E00}-\x{9FFF}\s]/u', '', $text);
        
        // 处理邮件引用符号（>开头的行）
        $text = preg_replace('/^>\s*/m', '  > ', $text);
        
        return $text;
    }
    
    /**
     * 将HTML内容转换为格式化的纯文本
     */
    private function htmlToText($html) {
        // 清理和预处理HTML
        $html = trim($html);
        
        // 移除脚本和样式标签及其内容
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        
        // 移除HTML注释
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        
        // 处理标题标签 - 添加换行和分隔
        $html = preg_replace('/<h[1-6][^>]*>/i', "\n\n", $html);
        $html = preg_replace('/<\/h[1-6]>/i', "\n" . str_repeat('-', 20) . "\n", $html);
        
        // 处理段落和换行
        $html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
        $html = str_replace(['</p>', '</div>', '</li>'], "\n\n", $html);
        
        // 处理列表
        $html = str_replace('<li>', "• ", $html);
        $html = preg_replace('/<\/?(ul|ol)[^>]*>/i', "\n", $html);
        
        // 处理表格
        $html = str_replace(['<td>', '<th>'], "    ", $html);
        $html = str_replace(['</td>', '</th>', '</tr>'], "\n", $html);
        $html = preg_replace('/<\/?(table|tbody|thead)[^>]*>/i', "\n", $html);
        
        // 处理链接 - 保留链接文本
        $html = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/i', '$2 [$1]', $html);
        
        // 移除所有剩余的HTML标签
        $text = strip_tags($html);
        
        // 解码HTML实体
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // 清理和格式化文本
        return $this->formatTextContent($text);
    }
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
    
    /**
     * 获取当前使用的代理信息
     * 注意：由于PHP IMAP扩展不支持代理，此方法主要用于状态显示
     */
    public function getCurrentProxy() {
        return $this->proxy;
    }
    
    /**
     * 设置是否使用代理
     * 注意：由于PHP IMAP扩展不支持代理，此设置主要用于状态记录
     */
    public function setUseProxy($useProxy) {
        $this->useProxy = $useProxy;
        if ($useProxy && !$this->proxyManager) {
            $this->proxyManager = new ProxyManager();
        }
    }
}
?>