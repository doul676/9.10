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
    
    public function __construct($server, $port, $username, $password, $ssl = true, $proxy = null) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->ssl = $ssl;
        $this->proxy = $proxy;
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
            30,
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
            30
        );
        
        if (!$proxySocket) {
            throw new Exception("Proxy connection failed: $errstr ($errno)");
        }
        
        // Send CONNECT request
        $connectRequest = "CONNECT {$this->server}:{$this->port} HTTP/1.1\r\n";
        $connectRequest .= "Host: {$this->server}:{$this->port}\r\n";
        
        // Add authentication if provided
        if (!empty($this->proxy['proxy_username']) && !empty($this->proxy['proxy_password'])) {
            $auth = base64_encode($this->proxy['proxy_username'] . ':' . $this->proxy['proxy_password']);
            $connectRequest .= "Proxy-Authorization: Basic $auth\r\n";
        }
        
        $connectRequest .= "\r\n";
        
        fwrite($proxySocket, $connectRequest);
        
        // Read response
        $response = fgets($proxySocket);
        if (!preg_match('/HTTP\/1\.[01]\s+200\s+/', $response)) {
            fclose($proxySocket);
            throw new Exception('Proxy CONNECT failed: ' . trim($response));
        }
        
        // Read headers until empty line
        while (($line = fgets($proxySocket)) !== false) {
            if (trim($line) === '') {
                break;
            }
        }
        
        // If SSL is required, enable crypto on the tunnel
        if ($this->ssl) {
            $crypto_result = stream_socket_enable_crypto(
                $proxySocket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            
            if (!$crypto_result) {
                fclose($proxySocket);
                throw new Exception('SSL handshake through proxy failed');
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
            30
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
        
        if ($auth_response[0] !== "\x05") {
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
                
                if ($auth_result[1] !== "\x00") {
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
            $connect_request = "\x05\x01\x00\x01" . inet_pton($ip) . pack('n', $this->port);
        }
        
        fwrite($proxySocket, $connect_request);
        $connect_response = fread($proxySocket, 10);
        
        if ($connect_response[0] !== "\x05" || $connect_response[1] !== "\x00") {
            fclose($proxySocket);
            $error_code = ord($connect_response[1]);
            throw new Exception("SOCKS5 connection failed with error code: $error_code");
        }
        
        // If SSL is required, enable crypto on the tunnel
        if ($this->ssl) {
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
     * Perform IMAP protocol handshake
     */
    private function performImapHandshake() {
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
     * Get the latest email
     */
    public function getLatestEmail() {
        if (!$this->connected) {
            throw new Exception('Not connected to IMAP server');
        }
        
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
        
        // Get the latest message (highest ID)
        $latestId = max($messageIds);
        
        // Fetch message envelope first, then body separately for better parsing
        $tag = 'A004';
        $command = "$tag FETCH $latestId (ENVELOPE)\r\n";
        fwrite($this->socket, $command);
        
        $envelope = null;
        $fullResponse = '';
        
        while (($line = $this->readLine()) !== false) {
            $fullResponse .= $line;
            if (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                if (!preg_match("/^$tag OK/", $line)) {
                    throw new Exception('ENVELOPE FETCH command failed: ' . $line);
                }
                break;
            }
        }
        
        // Parse the full ENVELOPE response
        if (preg_match('/\*\s+\d+\s+FETCH\s+\(ENVELOPE\s+\(([^}]+)\)\s*\)/s', $fullResponse, $matches)) {
            $envelope = $this->parseEnvelope($matches[1]);
        }
        
        if (!$envelope) {
            throw new Exception('Failed to parse email envelope from response: ' . substr($fullResponse, 0, 500));
        }
        
        // Now fetch the body separately
        $tag = 'A005';
        $command = "$tag FETCH $latestId (BODY[TEXT])\r\n";
        fwrite($this->socket, $command);
        
        $body = '';
        $bodyLines = [];
        $inBodyData = false;
        
        while (($line = $this->readLine()) !== false) {
            if (preg_match('/BODY\[TEXT\]\s+\{(\d+)\}/', $line, $matches)) {
                // Literal string follows
                $bodySize = intval($matches[1]);
                $body = fread($this->socket, $bodySize);
                fgets($this->socket); // Read the trailing CRLF
            } elseif (preg_match('/BODY\[TEXT\]\s+"([^"]*)"/', $line, $matches)) {
                // Quoted string
                $body = $this->decodeBody($matches[1]);
            } elseif (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                if (!preg_match("/^$tag OK/", $line)) {
                    throw new Exception('BODY FETCH command failed: ' . $line);
                }
                break;
            }
        }
        
        return [
            'success' => true,
            'mail' => [
                'subject' => $envelope['subject'] ?? 'No Subject',
                'from' => $envelope['from'] ?? 'Unknown',
                'from_email' => $envelope['from_email'] ?? 'Unknown',
                'from_name' => $envelope['from_name'] ?? '',
                'to' => $envelope['to'] ?? 'Unknown',
                'date' => $envelope['date'] ?? '',
                'body' => $body ?: 'No body content',
                'message_id' => $envelope['message_id'] ?? '',
                'size' => strlen($body)
            ]
        ];
    }
    
    /**
     * Parse IMAP ENVELOPE response
     */
    private function parseEnvelope($envelopeStr) {
        // IMAP ENVELOPE format: (date subject from sender reply-to to cc bcc in-reply-to message-id)
        // Each field can be NIL or a structured list
        
        try {
            // Clean up the envelope string
            $envelopeStr = trim($envelopeStr);
            
            // Split the envelope into fields - this is a simplified parser
            // For production use, you'd want a more robust parser that handles nested parentheses
            $fields = $this->parseImapList($envelopeStr);
            
            if (count($fields) < 10) {
                // Not enough fields, return defaults
                return [
                    'subject' => 'Unknown Subject',
                    'from' => 'Unknown',
                    'from_email' => 'unknown@unknown.com',
                    'from_name' => 'Unknown',
                    'to' => 'unknown@unknown.com',
                    'date' => date('Y-m-d H:i:s'),
                    'message_id' => '<unknown@unknown.com>'
                ];
            }
            
            // Parse date (field 0)
            $date = $this->cleanImapValue($fields[0]);
            $formattedDate = $this->parseImapDate($date);
            
            // Parse subject (field 1)
            $subject = $this->cleanImapValue($fields[1]);
            $subject = $this->decodeHeaderValue($subject);
            
            // Parse from (field 2)
            $fromData = $this->parseAddressList($fields[2]);
            $fromEmail = $fromData['email'] ?? 'unknown@unknown.com';
            $fromName = $fromData['name'] ?? '';
            
            // Parse to (field 5)
            $toData = $this->parseAddressList($fields[5]);
            $toEmail = $toData['email'] ?? 'unknown@unknown.com';
            
            // Parse message-id (field 9)
            $messageId = $this->cleanImapValue($fields[9]);
            
            return [
                'subject' => $subject ?: 'No Subject',
                'from' => $fromName ?: $fromEmail,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to' => $toEmail,
                'date' => $formattedDate,
                'message_id' => $messageId
            ];
            
        } catch (Exception $e) {
            error_log('Envelope parsing error: ' . $e->getMessage());
            // Return defaults if parsing fails
            return [
                'subject' => 'Parse Error',
                'from' => 'unknown@unknown.com',
                'from_email' => 'unknown@unknown.com',
                'from_name' => '',
                'to' => 'unknown@unknown.com',
                'date' => date('Y-m-d H:i:s'),
                'message_id' => '<parse.error@unknown.com>'
            ];
        }
    }
    
    /**
     * Parse IMAP list format (simplified)
     */
    private function parseImapList($str) {
        $str = trim($str);
        if (empty($str) || $str === 'NIL') {
            return [];
        }
        
        // Remove outer parentheses
        if ($str[0] === '(' && $str[strlen($str)-1] === ')') {
            $str = substr($str, 1, -1);
        }
        
        $fields = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $escapeNext = false;
        
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            
            if ($escapeNext) {
                $current .= $char;
                $escapeNext = false;
                continue;
            }
            
            if ($char === '\\') {
                $escapeNext = true;
                $current .= $char;
                continue;
            }
            
            if ($char === '"' && !$escapeNext) {
                $inQuotes = !$inQuotes;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ' ' && $depth === 0) {
                    $fields[] = trim($current);
                    $current = '';
                    continue;
                }
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $fields[] = trim($current);
        }
        
        return $fields;
    }
    
    /**
     * Parse IMAP address list
     */
    private function parseAddressList($addressStr) {
        $addressStr = trim($addressStr);
        
        if ($addressStr === 'NIL' || empty($addressStr)) {
            return ['email' => 'unknown@unknown.com', 'name' => ''];
        }
        
        // Remove outer parentheses if present
        if ($addressStr[0] === '(' && $addressStr[strlen($addressStr)-1] === ')') {
            $addressStr = substr($addressStr, 1, -1);
        }
        
        // Address format: ((name NIL mailbox host))
        if (preg_match('/\(\s*([^)]*)\s*\)/', $addressStr, $matches)) {
            $parts = $this->parseImapList($matches[1]);
            if (count($parts) >= 4) {
                $name = $this->cleanImapValue($parts[0]);
                $mailbox = $this->cleanImapValue($parts[2]);
                $host = $this->cleanImapValue($parts[3]);
                
                $email = $mailbox && $host ? "$mailbox@$host" : 'unknown@unknown.com';
                $name = $this->decodeHeaderValue($name);
                
                return ['email' => $email, 'name' => $name];
            }
        }
        
        return ['email' => 'unknown@unknown.com', 'name' => ''];
    }
    
    /**
     * Clean IMAP value (remove quotes, handle NIL)
     */
    private function cleanImapValue($value) {
        $value = trim($value);
        
        if ($value === 'NIL' || $value === 'nil') {
            return '';
        }
        
        // Remove quotes
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value)-1] === '"') {
            $value = substr($value, 1, -1);
            // Unescape quotes
            $value = str_replace('\\"', '"', $value);
            $value = str_replace('\\\\', '\\', $value);
        }
        
        return $value;
    }
    
    /**
     * Parse IMAP date
     */
    private function parseImapDate($dateStr) {
        if (empty($dateStr)) {
            return date('Y-m-d H:i:s');
        }
        
        $timestamp = strtotime($dateStr);
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }
        
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * Decode header value (handle MIME encoding)
     */
    private function decodeHeaderValue($value) {
        if (empty($value)) {
            return '';
        }
        
        // Handle MIME encoded headers like =?UTF-8?B?...?=
        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($value);
        } elseif (function_exists('iconv_mime_decode')) {
            return iconv_mime_decode($value, 0, 'UTF-8');
        }
        
        return $value;
    }
    
    /**
     * Decode email body
     */
    private function decodeBody($body) {
        if (empty($body)) {
            return '';
        }
        
        // Remove quotes if present
        $body = trim($body, '"');
        
        // Replace IMAP line endings
        $body = str_replace(['\r\n', '\n\r', '\n', '\r'], ["\n", "\n", "\n", "\n"], $body);
        
        // Decode base64 if needed (simple check)
        if (preg_match('/^[A-Za-z0-9+\/]+=*$/', trim($body)) && strlen($body) > 50) {
            $decoded = base64_decode($body, true);
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                return $decoded;
            }
        }
        
        // Decode quoted-printable if needed
        if (strpos($body, '=') !== false && preg_match('/=\w{2}/', $body)) {
            $decoded = quoted_printable_decode($body);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        
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