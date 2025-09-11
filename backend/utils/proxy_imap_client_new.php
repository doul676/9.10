<?php
/**
 * Proxy-capable IMAP Client
 * Supports HTTP and SOCKS5 proxies for IMAP connections
 * Replacement for webklex/php-imap that truly supports proxy connections
 */

class ProxyImapClient {
    private $server;
    private $port;
    private $username;
    private $password;
    private $ssl;
    private $proxy;
    private $socket;
    private $connected = false;
    private $protocol;
    private $connectionTimeout = 15; // Configurable connection timeout
    
    public function __construct($server, $port, $username, $password, $ssl = true, $proxy = null, $protocol = 'imap') {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->ssl = $ssl;
        $this->proxy = $proxy;
        $this->protocol = strtolower($protocol);
    }
    
    /**
     * Connect to IMAP server with optional proxy
     */
    public function connect() {
        try {
            if ($this->proxy) {
                return $this->connectViaProxy();
            } else {
                return $this->connectDirect();
            }
        } catch (Exception $e) {
            throw new Exception('IMAP连接失败: ' . $e->getMessage());
        }
    }
    
    /**
     * Connect directly to IMAP server
     */
    private function connectDirect() {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        $target = ($this->ssl ? 'ssl://' : 'tcp://') . $this->server . ':' . $this->port;
        
        $this->socket = stream_socket_client(
            $target,
            $errno,
            $errstr,
            $this->connectionTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$this->socket) {
            throw new Exception("Direct connection failed: $errstr ($errno)");
        }
        
        return $this->performImapHandshake();
    }
    
    /**
     * Connect via proxy (HTTP or SOCKS5)
     */
    private function connectViaProxy() {
        if ($this->proxy['proxy_type'] === 'http') {
            return $this->connectViaHttpProxy();
        } elseif ($this->proxy['proxy_type'] === 'socks5') {
            return $this->connectViaSocks5Proxy();
        } else {
            throw new Exception('Unsupported proxy type: ' . $this->proxy['proxy_type']);
        }
    }
    
    /**
     * Connect via HTTP proxy using CONNECT method
     */
    private function connectViaHttpProxy() {
        // First connect to the proxy
        $proxySocket = stream_socket_client(
            'tcp://' . $this->proxy['proxy_host'] . ':' . $this->proxy['proxy_port'],
            $errno,
            $errstr,
            $this->connectionTimeout
        );
        
        if (!$proxySocket) {
            throw new Exception("HTTP Proxy connection failed: $errstr ($errno)");
        }
        
        // Send CONNECT request
        $connectRequest = "CONNECT {$this->server}:{$this->port} HTTP/1.1\r\n";
        $connectRequest .= "Host: {$this->server}:{$this->port}\r\n";
        $connectRequest .= "User-Agent: IMAP-Client/1.0\r\n";
        
        // Add authentication if provided
        if (!empty($this->proxy['proxy_username']) && !empty($this->proxy['proxy_password'])) {
            $auth = base64_encode($this->proxy['proxy_username'] . ':' . $this->proxy['proxy_password']);
            $connectRequest .= "Proxy-Authorization: Basic $auth\r\n";
        }
        
        $connectRequest .= "Connection: keep-alive\r\n";
        $connectRequest .= "\r\n";
        
        fwrite($proxySocket, $connectRequest);
        
        // Read response
        $response = fgets($proxySocket);
        if (!preg_match('/HTTP\/1\.[01]\s+200\s+/', $response)) {
            fclose($proxySocket);
            throw new Exception('HTTP Proxy CONNECT failed: ' . trim($response));
        }
        
        // Read headers until empty line
        while (($line = fgets($proxySocket)) !== false) {
            if (trim($line) === '') {
                break;
            }
        }
        
        // If SSL is required, enable crypto on the tunnel
        if ($this->ssl) {
            // Set up SSL context with proper options
            $sslContext = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'capture_peer_cert' => true,
                    'SNI_enabled' => true,
                    'peer_name' => $this->server,
                ]
            ]);
            
            stream_context_set_option($proxySocket, 'ssl', 'verify_peer', false);
            stream_context_set_option($proxySocket, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($proxySocket, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($proxySocket, 'ssl', 'peer_name', $this->server);
            
            $crypto_result = stream_socket_enable_crypto(
                $proxySocket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            
            if (!$crypto_result) {
                fclose($proxySocket);
                throw new Exception('SSL handshake through HTTP proxy failed');
            }
        }
        
        $this->socket = $proxySocket;
        return $this->performImapHandshake();
    }
    
    /**
     * Connect via SOCKS5 proxy
     */
    private function connectViaSocks5Proxy() {
        // Connect to SOCKS5 proxy
        $proxySocket = stream_socket_client(
            'tcp://' . $this->proxy['proxy_host'] . ':' . $this->proxy['proxy_port'],
            $errno,
            $errstr,
            $this->connectionTimeout
        );
        
        if (!$proxySocket) {
            throw new Exception("SOCKS5 proxy connection failed: $errstr ($errno)");
        }
        
        // SOCKS5 authentication negotiation
        if (!empty($this->proxy['proxy_username']) && !empty($this->proxy['proxy_password'])) {
            // Authentication required
            $auth_request = "\x05\x02\x00\x02"; // Version 5, 2 methods, no auth + username/password
        } else {
            // No authentication
            $auth_request = "\x05\x01\x00"; // Version 5, 1 method, no auth
        }
        
        fwrite($proxySocket, $auth_request);
        $auth_response = fread($proxySocket, 2);
        
        if (strlen($auth_response) < 2 || $auth_response[0] !== "\x05") {
            fclose($proxySocket);
            throw new Exception('SOCKS5 proxy version not supported');
        }
        
        // Handle authentication
        if (!empty($this->proxy['proxy_username']) && !empty($this->proxy['proxy_password'])) {
            if ($auth_response[1] === "\x02") {
                // Username/password authentication
                $username = $this->proxy['proxy_username'];
                $password = $this->proxy['proxy_password'];
                $auth_data = "\x01" . chr(strlen($username)) . $username . chr(strlen($password)) . $password;
                
                fwrite($proxySocket, $auth_data);
                $auth_result = fread($proxySocket, 2);
                
                if (strlen($auth_result) < 2 || $auth_result[1] !== "\x00") {
                    fclose($proxySocket);
                    throw new Exception('SOCKS5 proxy authentication failed');
                }
            } elseif ($auth_response[1] === "\xFF") {
                fclose($proxySocket);
                throw new Exception('SOCKS5 proxy rejected all authentication methods');
            }
        } elseif ($auth_response[1] !== "\x00") {
            fclose($proxySocket);
            throw new Exception('SOCKS5 proxy requires authentication');
        }
        
        // SOCKS5 connection request
        $ip = gethostbyname($this->server);
        if ($ip === $this->server) {
            // Domain name resolution failed, use domain name
            $connect_request = "\x05\x01\x00\x03" . chr(strlen($this->server)) . $this->server . pack('n', $this->port);
        } else {
            // Use IP address
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $connect_request = "\x05\x01\x00\x01" . inet_pton($ip) . pack('n', $this->port);
            } else {
                // Fallback to domain name if IP parsing fails
                $connect_request = "\x05\x01\x00\x03" . chr(strlen($this->server)) . $this->server . pack('n', $this->port);
            }
        }
        
        fwrite($proxySocket, $connect_request);
        $connect_response = fread($proxySocket, 4);
        
        if (strlen($connect_response) < 4 || $connect_response[0] !== "\x05" || $connect_response[1] !== "\x00") {
            fclose($proxySocket);
            $error_code = strlen($connect_response) >= 2 ? ord($connect_response[1]) : 0;
            $error_messages = [
                1 => 'General SOCKS server failure',
                2 => 'Connection not allowed by ruleset',
                3 => 'Network unreachable',
                4 => 'Host unreachable',
                5 => 'Connection refused',
                6 => 'TTL expired',
                7 => 'Command not supported',
                8 => 'Address type not supported'
            ];
            $error_msg = $error_messages[$error_code] ?? "Unknown error code: $error_code";
            throw new Exception("SOCKS5 connection failed: $error_msg");
        }
        
        // Read the rest of the response (address and port)
        $address_type = $connect_response[3];
        if ($address_type === "\x01") {
            // IPv4
            fread($proxySocket, 6); // 4 bytes IP + 2 bytes port
        } elseif ($address_type === "\x03") {
            // Domain name
            $domain_length = ord(fread($proxySocket, 1));
            fread($proxySocket, $domain_length + 2); // domain + 2 bytes port
        } elseif ($address_type === "\x04") {
            // IPv6
            fread($proxySocket, 18); // 16 bytes IP + 2 bytes port
        }
        
        // If SSL is required, enable crypto on the tunnel
        if ($this->ssl) {
            $sslContext = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'capture_peer_cert' => true,
                    'SNI_enabled' => true,
                    'peer_name' => $this->server,
                ]
            ]);
            
            stream_context_set_option($proxySocket, 'ssl', 'verify_peer', false);
            stream_context_set_option($proxySocket, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($proxySocket, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($proxySocket, 'ssl', 'peer_name', $this->server);
            
            $crypto_result = stream_socket_enable_crypto(
                $proxySocket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            
            if (!$crypto_result) {
                fclose($proxySocket);
                throw new Exception('SSL handshake through SOCKS5 proxy failed');
            }
        }
        
        $this->socket = $proxySocket;
        return $this->performImapHandshake();
    }
    
    /**
     * Perform protocol handshake (IMAP or POP3)
     */
    private function performImapHandshake() {
        if ($this->protocol === 'pop3') {
            return $this->performPop3Handshake();
        } else {
            return $this->performImapLoginHandshake();
        }
    }
    
    /**
     * Perform IMAP handshake
     */
    private function performImapLoginHandshake() {
        // Read server greeting
        $greeting = $this->readLine();
        if (!preg_match('/^\*\s+OK/', $greeting)) {
            throw new Exception('Invalid IMAP server greeting: ' . $greeting);
        }
        
        // Send LOGIN command
        $tag = 'A001';
        $login_command = "$tag LOGIN " . $this->escapeString($this->username) . " " . $this->escapeString($this->password) . "\r\n";
        fwrite($this->socket, $login_command);
        
        // Read response
        $response = $this->readLine();
        if (!preg_match("/^$tag OK/", $response)) {
            throw new Exception('IMAP login failed: ' . $response);
        }
        
        $this->connected = true;
        return true;
    }
    
    /**
     * Perform POP3 handshake  
     */
    private function performPop3Handshake() {
        // Read server greeting
        $greeting = $this->readLine();
        if (!preg_match('/^\+OK/', $greeting)) {
            throw new Exception('Invalid POP3 server greeting: ' . $greeting);
        }
        
        // Send USER command
        $user_command = "USER " . $this->username . "\r\n";
        fwrite($this->socket, $user_command);
        
        $response = $this->readLine();
        if (!preg_match('/^\+OK/', $response)) {
            throw new Exception('POP3 USER failed: ' . $response);
        }
        
        // Send PASS command
        $pass_command = "PASS " . $this->password . "\r\n";
        fwrite($this->socket, $pass_command);
        
        $response = $this->readLine();
        if (!preg_match('/^\+OK/', $response)) {
            throw new Exception('POP3 PASS failed: ' . $response);
        }
        
        $this->connected = true;
        return true;
    }
    
    /**
     * Select INBOX folder
     */
    public function selectInbox() {
        if (!$this->connected) {
            throw new Exception('Not connected to IMAP server');
        }
        
        $tag = 'A002';
        $command = "$tag SELECT INBOX\r\n";
        fwrite($this->socket, $command);
        
        // Read response until we get the tagged response
        $response = '';
        while (($line = $this->readLine()) !== false) {
            $response .= $line . "\n";
            if (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                if (preg_match("/^$tag OK/", $line)) {
                    return true;
                } else {
                    throw new Exception('INBOX selection failed: ' . $line);
                }
            }
        }
        
        throw new Exception('Unexpected end of response while selecting INBOX');
    }
    
    /**
     * Parse complete FETCH response
     */
    private function parseFetchResponse($response, $messageId) {
        try {
            // Initialize default values
            $mail = [
                'subject' => 'No Subject',
                'from' => 'Unknown',
                'from_email' => 'unknown@unknown.com',
                'from_name' => 'Unknown',
                'to' => 'Unknown',
                'date' => date('Y-m-d H:i:s'),
                'body' => '',
                'message_id' => '<msg-' . $messageId . '@imap>',
                'size' => 0
            ];
            
            // Extract headers section
            if (preg_match('/BODY\[HEADER\.FIELDS[^\]]*\]\s*{[^}]*}\s*([^}]+?)\s*\)/s', $response, $headerMatches)) {
                $headers = $headerMatches[1];
                
                // Parse individual headers
                if (preg_match('/From:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
                    $fromHeader = trim($matches[1]);
                    $mail['from'] = $fromHeader;
                    
                    // Extract email and name from From header
                    if (preg_match('/(.*?)<([^>]+)>/', $fromHeader, $fromMatches)) {
                        $mail['from_name'] = trim($fromMatches[1], ' "');
                        $mail['from_email'] = trim($fromMatches[2]);
                    } elseif (preg_match('/([^@]+@[^@]+)/', $fromHeader, $emailMatches)) {
                        $mail['from_email'] = $emailMatches[1];
                        $mail['from_name'] = $emailMatches[1];
                    }
                }
                
                if (preg_match('/Subject:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
                    $mail['subject'] = $this->decodeImapUtf8(trim($matches[1]));
                }
                
                if (preg_match('/To:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
                    $mail['to'] = trim($matches[1]);
                }
                
                if (preg_match('/Date:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
                    $mail['date'] = trim($matches[1]);
                }
            }
            
            // Extract body content with improved parsing
            if (preg_match('/BODY\[TEXT\]\s*{[^}]*}\s*([^}]+?)\s*\)/s', $response, $bodyMatches)) {
                $rawBody = trim($bodyMatches[1]);
                $mail['body'] = $this->cleanAndDecodeBody($rawBody);
                $mail['size'] = strlen($mail['body']);
            } elseif (preg_match('/BODY\[TEXT\]\s*"([^"]*)"/', $response, $bodyMatches)) {
                $rawBody = $this->decodeBody($bodyMatches[1]);
                $mail['body'] = $this->cleanAndDecodeBody($rawBody);
                $mail['size'] = strlen($mail['body']);
            } else {
                // Fallback: try to extract any text content
                if (preg_match('/BODY\[.*?\]\s*{[^}]*}\s*([^}]+)/s', $response, $bodyMatches)) {
                    $rawBody = trim($bodyMatches[1]);
                    $mail['body'] = $this->cleanAndDecodeBody($rawBody);
                    $mail['size'] = strlen($mail['body']);
                }
            }
            
            // Ensure we have some content
            if (empty($mail['body'])) {
                $mail['body'] = '(邮件内容为空或解析失败)';
            }
            
            return $mail;
            
        } catch (Exception $e) {
            error_log('FETCH response parsing error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Decode IMAP UTF-8 strings
     */
    private function decodeImapUtf8($str) {
        // Handle IMAP modified UTF-7 and regular UTF-8
        if (preg_match('/=\?([^?]+)\?([BQ])\?([^?]+)\?=/', $str, $matches)) {
            $charset = $matches[1];
            $encoding = $matches[2];
            $text = $matches[3];
            
            if ($encoding === 'B') {
                $text = base64_decode($text);
            } elseif ($encoding === 'Q') {
                $text = quoted_printable_decode(str_replace('_', ' ', $text));
            }
            
            return $text;
        }
        
        return $str;
    }

    /**
     * Find latest message by date instead of by ID
     */
    private function findLatestMessageByDate($messageIds) {
        if (empty($messageIds)) {
            return null;
        }
        
        // If only one message, return it
        if (count($messageIds) === 1) {
            return $messageIds[0];
        }
        
        $latestId = null;
        $latestTimestamp = 0;
        
        // For each message, get its date and find the most recent
        foreach ($messageIds as $id) {
            try {
                $tag = 'A00' . $id;
                $command = "$tag FETCH $id (ENVELOPE)\r\n";
                fwrite($this->socket, $command);
                
                $envelopeResponse = '';
                while (($line = $this->readLine()) !== false) {
                    $envelopeResponse .= $line;
                    if (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                        break;
                    }
                }
                
                // Extract date from envelope
                if (preg_match('/ENVELOPE \([^"]*"([^"]*)"/', $envelopeResponse, $matches)) {
                    $dateStr = $matches[1];
                    $timestamp = strtotime($dateStr);
                    if ($timestamp > $latestTimestamp) {
                        $latestTimestamp = $timestamp;
                        $latestId = $id;
                    }
                }
            } catch (Exception $e) {
                // If we can't get date for this message, skip it
                error_log('Could not get date for message ' . $id . ': ' . $e->getMessage());
                continue;
            }
        }
        
        // Fallback to highest ID if date parsing failed for all messages
        return $latestId ?: max($messageIds);
    }

    /**
     * Get the latest email with improved parsing
     */
    public function getLatestEmail() {
        if (!$this->connected) {
            throw new Exception('Not connected to mail server');
        }
        
        if ($this->protocol === 'pop3') {
            return $this->getLatestEmailPOP3();
        } else {
            return $this->getLatestEmailIMAP();
        }
    }
    
    /**
     * Get latest email using IMAP protocol
     */
    private function getLatestEmailIMAP() {
        $this->selectInbox();
        
        // Search for all messages
        $tag = 'A003';
        $command = "$tag SEARCH ALL\r\n";
        fwrite($this->socket, $command);
        
        $messageIds = [];
        while (($line = $this->readLine()) !== false) {
            if (preg_match('/^\*\s+SEARCH\s+(.+)/', $line, $matches)) {
                $messageIds = array_filter(explode(' ', trim($matches[1])));
            } elseif (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                if (!preg_match("/^$tag OK/", $line)) {
                    throw new Exception('SEARCH command failed: ' . $line);
                }
                break;
            }
        }
        
        if (empty($messageIds)) {
            return [
                'success' => true,
                'message' => '邮箱中没有邮件',
                'mail' => null
            ];
        }
        
        // Get the latest message by date, not just highest ID
        $latestId = $this->findLatestMessageByDate($messageIds);
        
        // Fetch message with better field selection
        $tag = 'A004';
        $command = "$tag FETCH $latestId (ENVELOPE BODY[HEADER.FIELDS (FROM TO SUBJECT DATE)] BODY[TEXT])\r\n";
        fwrite($this->socket, $command);
        
        $envelope = null;
        $headers = '';
        $body = '';
        $fetchResponse = '';
        
        while (($line = $this->readLine()) !== false) {
            $fetchResponse .= $line . "\n";
            
            if (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                if (!preg_match("/^$tag OK/", $line)) {
                    throw new Exception('FETCH command failed: ' . $line);
                }
                break;
            }
        }
        
        // Parse the complete FETCH response
        $mail = $this->parseFetchResponse($fetchResponse, $latestId);
        
        if (!$mail) {
            // Fallback to simple parsing if complex parsing fails
            $envelope = [
                'subject' => 'Email Retrieved',
                'from' => 'Unknown Sender',
                'from_email' => 'unknown@unknown.com',
                'from_name' => 'Unknown',
                'to' => 'You',
                'date' => date('Y-m-d H:i:s'),
                'message_id' => '<msg-' . $latestId . '@imap>'
            ];
            
            $mail = [
                'subject' => $envelope['subject'],
                'from' => $envelope['from'],
                'from_email' => $envelope['from_email'],
                'from_name' => $envelope['from_name'],
                'to' => $envelope['to'],
                'date' => $envelope['date'],
                'body' => 'Email content could not be parsed completely',
                'message_id' => $envelope['message_id'],
                'size' => 0
            ];
        }
        
        return [
            'success' => true,
            'mail' => $mail
        ];
    }
    
    /**
     * Get latest email using POP3 protocol with proxy support
     */
    private function getLatestEmailPOP3() {
        // Get number of messages
        fwrite($this->socket, "STAT\r\n");
        $response = $this->readLine();
        
        if (!preg_match('/^\+OK\s+(\d+)/', $response, $matches)) {
            throw new Exception('POP3 STAT failed: ' . $response);
        }
        
        $messageCount = (int)$matches[1];
        
        if ($messageCount === 0) {
            return [
                'success' => true,
                'message' => '邮箱中没有邮件',
                'mail' => null
            ];
        }
        
        // For POP3, messages are numbered 1 to N, and typically the highest number is the newest
        // But to be sure, let's get the date of the last few messages and find the most recent
        $latestMessageNum = $this->findLatestPOP3MessageByDate($messageCount);
        
        // Get the latest message headers and body
        $mail = $this->retrievePOP3Message($latestMessageNum);
        
        return [
            'success' => true,
            'mail' => $mail
        ];
    }
    
    /**
     * Find the latest POP3 message by comparing dates
     */
    private function findLatestPOP3MessageByDate($messageCount) {
        $latestNum = $messageCount; // Default to last message
        $latestTimestamp = 0;
        
        // Check last few messages to find the most recent by date
        $checkCount = min(5, $messageCount); // Check last 5 messages or all if fewer
        $startNum = max(1, $messageCount - $checkCount + 1);
        
        for ($num = $startNum; $num <= $messageCount; $num++) {
            try {
                // Get message headers
                fwrite($this->socket, "TOP $num 0\r\n");
                $response = $this->readLine();
                
                if (preg_match('/^\+OK/', $response)) {
                    $headers = '';
                    while (($line = $this->readLine()) !== false) {
                        if (trim($line) === '.') {
                            break;
                        }
                        $headers .= $line;
                    }
                    
                    // Extract date from headers
                    if (preg_match('/Date:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
                        $dateStr = trim($matches[1]);
                        $timestamp = strtotime($dateStr);
                        
                        if ($timestamp > $latestTimestamp) {
                            $latestTimestamp = $timestamp;
                            $latestNum = $num;
                        }
                    }
                }
            } catch (Exception $e) {
                // If we can't get headers for this message, skip it
                error_log('Could not get headers for POP3 message ' . $num . ': ' . $e->getMessage());
                continue;
            }
        }
        
        return $latestNum;
    }
    
    /**
     * Retrieve a specific POP3 message
     */
    private function retrievePOP3Message($messageNum) {
        // Get full message
        fwrite($this->socket, "RETR $messageNum\r\n");
        $response = $this->readLine();
        
        if (!preg_match('/^\+OK/', $response)) {
            throw new Exception('POP3 RETR failed: ' . $response);
        }
        
        $fullMessage = '';
        while (($line = $this->readLine()) !== false) {
            if (trim($line) === '.') {
                break;
            }
            $fullMessage .= $line;
        }
        
        // Parse the message
        return $this->parsePOP3Message($fullMessage);
    }
    
    /**
     * Parse POP3 message format
     */
    private function parsePOP3Message($message) {
        // Split headers and body
        $parts = preg_split('/\r?\n\r?\n/', $message, 2);
        $headers = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        
        // Initialize default values
        $mail = [
            'subject' => 'No Subject',
            'from' => 'Unknown',
            'from_email' => 'unknown@unknown.com',
            'from_name' => 'Unknown',
            'to' => 'Unknown',
            'date' => date('Y-m-d H:i:s'),
            'body' => '',
            'message_id' => '<unknown@unknown.com>',
            'size' => strlen($message)
        ];
        
        // Parse headers
        if (preg_match('/From:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
            $fromHeader = trim($matches[1]);
            $mail['from'] = $fromHeader;
            
            // Extract email and name from From header
            if (preg_match('/(.*?)<([^>]+)>/', $fromHeader, $fromMatches)) {
                $mail['from_name'] = trim($fromMatches[1], ' "');
                $mail['from_email'] = trim($fromMatches[2]);
            } elseif (preg_match('/([^@]+@[^@]+)/', $fromHeader, $emailMatches)) {
                $mail['from_email'] = $emailMatches[1];
                $mail['from_name'] = $emailMatches[1];
            }
        }
        
        if (preg_match('/Subject:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
            $mail['subject'] = $this->decodeImapUtf8(trim($matches[1]));
        }
        
        if (preg_match('/To:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
            $mail['to'] = trim($matches[1]);
        }
        
        if (preg_match('/Date:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
            $dateStr = trim($matches[1]);
            $timestamp = strtotime($dateStr);
            if ($timestamp !== false) {
                $mail['date'] = date('Y-m-d H:i:s', $timestamp);
            } else {
                $mail['date'] = $dateStr;
            }
        }
        
        if (preg_match('/Message-ID:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
            $mail['message_id'] = trim($matches[1]);
        }
        
        // Handle multipart messages
        if (preg_match('/Content-Type:\s*multipart/i', $headers)) {
            $mail['body'] = $this->parseMultipartPOP3Body($body, $headers);
        } else {
            // Single part message
            $mail['body'] = $this->decodePOP3Body($body, $headers);
        }
        
        return $mail;
    }
    
    /**
     * Parse multipart POP3 body
     */
    private function parseMultipartPOP3Body($body, $headers) {
        // Extract boundary from Content-Type header
        $boundary = '';
        if (preg_match('/boundary=([^;\s]+)/i', $headers, $matches)) {
            $boundary = trim($matches[1], '"\'');
        }
        
        if (empty($boundary)) {
            // No boundary found, treat as single part
            return $this->decodePOP3Body($body, $headers);
        }
        
        // Split message by boundary
        $parts = preg_split('/--' . preg_quote($boundary, '/') . '/', $body);
        $textContent = '';
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || $part === '--') {
                continue;
            }
            
            // Split part into headers and content
            $partParts = preg_split('/\r?\n\r?\n/', $part, 2);
            $partHeaders = $partParts[0] ?? '';
            $partContent = $partParts[1] ?? '';
            
            // Check if this is a text part
            if (preg_match('/Content-Type:\s*text\/plain/i', $partHeaders)) {
                $textContent .= $this->decodePOP3Body($partContent, $partHeaders) . "\n\n";
                break; // Use first text/plain part
            } elseif (preg_match('/Content-Type:\s*text\/html/i', $partHeaders) && empty($textContent)) {
                // Fallback to HTML if no plain text found
                $htmlContent = $this->decodePOP3Body($partContent, $partHeaders);
                $textContent = strip_tags($htmlContent); // Simple HTML to text conversion
            }
        }
        
        return trim($textContent) ?: '(邮件内容为空)';
    }
    
    /**
     * Decode POP3 message body
     */
    private function decodePOP3Body($body, $headers) {
        if (empty($body)) {
            return '(邮件内容为空)';
        }
        
        // Check for Content-Transfer-Encoding
        $encoding = '';
        if (preg_match('/Content-Transfer-Encoding:\s*(.+?)(?:\r?\n|\r)(?![[:space:]])/i', $headers, $matches)) {
            $encoding = strtolower(trim($matches[1]));
        }
        
        // Decode based on encoding
        switch ($encoding) {
            case 'base64':
                $decoded = base64_decode($body);
                if ($decoded !== false) {
                    $body = $decoded;
                }
                break;
            case 'quoted-printable':
                $body = quoted_printable_decode($body);
                break;
            case '8bit':
            case '7bit':
            default:
                // No decoding needed
                break;
        }
        
        // Handle charset conversion
        $charset = 'UTF-8';
        if (preg_match('/charset=([^;\s\r\n]+)/i', $headers, $matches)) {
            $charset = trim($matches[1], '"\'');
        }
        
        if (strtolower($charset) !== 'utf-8') {
            try {
                $converted = mb_convert_encoding($body, 'UTF-8', $charset);
                if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                    $body = $converted;
                } else {
                    // Try common encodings
                    $commonEncodings = ['GBK', 'GB2312', 'Big5', 'ISO-8859-1', 'Windows-1252'];
                    foreach ($commonEncodings as $encoding) {
                        try {
                            $converted = mb_convert_encoding($body, 'UTF-8', $encoding);
                            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                                $body = $converted;
                                break;
                            }
                        } catch (Exception $e) {
                            continue;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Charset conversion failed: ' . $e->getMessage());
            }
        }
        
        // Clean up content
        $body = trim($body);
        if (empty($body)) {
            return '(邮件内容为空)';
        }
        
        // Remove excessive whitespace but preserve structure
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        $body = preg_replace('/[ \t]+/', ' ', $body);
        
        if (mb_strlen($body, 'UTF-8') > 10000) {
            $body = mb_substr($body, 0, 10000, 'UTF-8') . "\n\n(内容过长，已截断...)";
        }
        
        return $body;
    }
    
    /**
     * Parse IMAP ENVELOPE response
     */
    private function parseEnvelope($envelopeStr) {
        try {
            // Remove extra whitespace and normalize
            $envelopeStr = trim($envelopeStr);
            
            // IMAP ENVELOPE structure: (date subject from sender reply-to to cc bcc in-reply-to message-id)
            // We need to parse this carefully as each field can be quoted strings, NIL, or nested structures
            
            // For now, implement a basic parser that can handle most common cases
            // This will extract basic information and return meaningful defaults for complex parsing
            
            // Default values
            $envelope = [
                'date' => date('Y-m-d H:i:s'),
                'subject' => 'No Subject',
                'from' => 'Unknown',
                'from_email' => 'unknown@unknown.com',
                'from_name' => 'Unknown Sender',
                'to' => 'Unknown',
                'message_id' => '<unknown@unknown.com>'
            ];
            
            // Try to extract basic fields using regex patterns
            // This is a simplified parser - for production, you'd want a full IMAP parser
            
            // Extract subject (usually the second field)
            if (preg_match('/^\([^"]*"([^"]*)"/', $envelopeStr, $matches)) {
                if (!empty($matches[1]) && $matches[1] !== 'NIL') {
                    $envelope['subject'] = $this->decodeImapUtf8($matches[1]);
                }
            }
            
            // For more complex parsing, we would need a proper IMAP envelope parser
            // But this basic implementation will prevent the "Failed to parse email envelope" error
            
            return $envelope;
            
        } catch (Exception $e) {
            // If parsing fails, return safe defaults to prevent errors
            error_log('Envelope parsing error: ' . $e->getMessage());
            return [
                'date' => date('Y-m-d H:i:s'),
                'subject' => 'Email parsing failed',
                'from' => 'System',
                'from_email' => 'system@localhost',
                'from_name' => 'System',
                'to' => 'User',
                'message_id' => '<system-' . time() . '@localhost>'
            ];
        }
    }
    
    /**
     * Clean and decode email body content
     */
    private function cleanAndDecodeBody($body) {
        if (empty($body)) {
            return '(邮件内容为空)';
        }
        
        // Remove any null characters or control characters
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body);
        
        // Clean up whitespace but preserve structure
        $body = preg_replace('/\r\n/', "\n", $body);
        $body = preg_replace('/\r/', "\n", $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        
        // Try to decode if it looks like base64 or quoted-printable
        if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', trim($body)) && strlen($body) > 20) {
            $decoded = base64_decode(preg_replace('/\s/', '', $body));
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                $body = $decoded;
            }
        } elseif (strpos($body, '=') !== false) {
            $decoded = quoted_printable_decode($body);
            if (mb_check_encoding($decoded, 'UTF-8')) {
                $body = $decoded;
            }
        }
        
        $body = trim($body);
        
        if (mb_strlen($body, 'UTF-8') > 10000) {
            $body = mb_substr($body, 0, 10000, 'UTF-8') . "\n\n(内容过长，已截断...)";
        }
        
        return $body ?: '(邮件内容为空)';
    }

    /**
     * Decode email body
     */
    private function decodeBody($body) {
        // Remove quotes and decode
        $body = trim($body, '"');
        $body = str_replace('\r\n', "\n", $body);
        return $body;
    }
    
    /**
     * Read a line from the socket
     */
    private function readLine() {
        return fgets($this->socket);
    }
    
    /**
     * Escape string for IMAP command
     */
    private function escapeString($str) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $str) . '"';
    }
    
    /**
     * Close the connection
     */
    public function close() {
        if ($this->socket) {
            if ($this->connected) {
                fwrite($this->socket, "A999 LOGOUT\r\n");
                $this->readLine(); // Read response
            }
            fclose($this->socket);
            $this->socket = null;
            $this->connected = false;
        }
    }
    
    /**
     * Test connection
     */
    public function testConnection() {
        try {
            if ($this->connect()) {
                $this->close();
                return [
                    'success' => true,
                    'message' => '连接成功',
                    'proxy_used' => $this->proxy !== null
                ];
            }
            return [
                'success' => false,
                'message' => '连接失败'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '连接失败: ' . $e->getMessage()
            ];
        }
    }
}
?>