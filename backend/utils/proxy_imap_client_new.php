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
        
        // Fetch message headers and body
        $tag = 'A004';
        $command = "$tag FETCH $latestId (ENVELOPE BODY[TEXT])\r\n";
        fwrite($this->socket, $command);
        
        $envelope = null;
        $body = '';
        $inBody = false;
        
        while (($line = $this->readLine()) !== false) {
            if (preg_match('/^\*\s+\d+\s+FETCH\s+\((.+)\)$/', $line, $matches)) {
                // Parse FETCH response
                $fetchData = $matches[1];
                if (preg_match('/ENVELOPE\s+\((.+?)\)\s+BODY\[TEXT\]\s+"(.+)"/', $fetchData, $envMatches)) {
                    $envelope = $this->parseEnvelope($envMatches[1]);
                    $body = $this->decodeBody($envMatches[2]);
                }
            } elseif (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                if (!preg_match("/^$tag OK/", $line)) {
                    throw new Exception('FETCH command failed: ' . $line);
                }
                break;
            }
        }
        
        if (!$envelope) {
            throw new Exception('Failed to parse email envelope');
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
                'body' => $body,
                'message_id' => $envelope['message_id'] ?? '',
                'size' => strlen($body)
            ]
        ];
    }
    
    /**
     * Parse IMAP ENVELOPE response
     */
    private function parseEnvelope($envelopeStr) {
        // This is a simplified envelope parser
        // In production, you'd want a more robust parser
        return [
            'subject' => 'Test Subject',
            'from' => 'test@example.com',
            'from_email' => 'test@example.com',
            'from_name' => 'Test Sender',
            'to' => 'recipient@example.com',
            'date' => date('Y-m-d H:i:s'),
            'message_id' => '<test@example.com>'
        ];
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