<?php
/**
 * Proxy-enabled IMAP Client
 * Custom implementation that supports HTTP and SOCKS5 proxies
 * Replaces PHP native IMAP extension for proxy connections
 */

class ProxyImapClient {
    private $server;
    private $port;
    private $username;
    private $password;
    private $ssl;
    private $proxy;
    private $socket;
    private $authenticated = false;
    private $selectedMailbox = null;
    private $lastResponse = '';
    private $lastError = '';
    private $tagCounter = 1000;
    
    public function __construct($server, $port, $username, $password, $ssl = true, $proxy = null) {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->ssl = $ssl;
        $this->proxy = $proxy;
    }
    
    /**
     * Connect to IMAP server through proxy
     */
    public function connect() {
        try {
            if ($this->proxy) {
                return $this->connectWithProxy();
            } else {
                return $this->connectDirect();
            }
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Direct connection without proxy
     */
    private function connectDirect() {
        $context = stream_context_create();
        
        if ($this->ssl) {
            // SSL/TLS connection
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
            
            $this->socket = stream_socket_client(
                "ssl://{$this->server}:{$this->port}",
                $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context
            );
        } else {
            // Plain connection
            $this->socket = stream_socket_client(
                "tcp://{$this->server}:{$this->port}",
                $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context
            );
        }
        
        if (!$this->socket) {
            throw new Exception("Connection failed: $errstr ($errno)");
        }
        
        // Read server greeting
        $greeting = $this->readResponse();
        if (!preg_match('/^\* OK/', $greeting)) {
            throw new Exception("Invalid server greeting: $greeting");
        }
        
        return $this->authenticate();
    }
    
    /**
     * Connect through proxy
     */
    private function connectWithProxy() {
        if ($this->proxy['proxy_type'] === 'http') {
            return $this->connectHttpProxy();
        } elseif ($this->proxy['proxy_type'] === 'socks5') {
            return $this->connectSocks5Proxy();
        } else {
            throw new Exception("Unsupported proxy type: " . $this->proxy['proxy_type']);
        }
    }
    
    /**
     * Connect through HTTP proxy
     */
    private function connectHttpProxy() {
        $proxyHost = $this->proxy['proxy_host'];
        $proxyPort = $this->proxy['proxy_port'];
        
        // Connect to proxy first
        $this->socket = stream_socket_client(
            "tcp://{$proxyHost}:{$proxyPort}",
            $errno, $errstr, 30
        );
        
        if (!$this->socket) {
            throw new Exception("Proxy connection failed: $errstr ($errno)");
        }
        
        // Send CONNECT request
        $connectCmd = "CONNECT {$this->server}:{$this->port} HTTP/1.1\r\n";
        $connectCmd .= "Host: {$this->server}:{$this->port}\r\n";
        
        // Add proxy authentication if provided
        if (!empty($this->proxy['proxy_username']) && !empty($this->proxy['proxy_password'])) {
            $auth = base64_encode($this->proxy['proxy_username'] . ':' . $this->proxy['proxy_password']);
            $connectCmd .= "Proxy-Authorization: Basic $auth\r\n";
        }
        
        $connectCmd .= "Connection: close\r\n\r\n";
        
        fwrite($this->socket, $connectCmd);
        
        // Read proxy response
        $response = fgets($this->socket);
        if (!preg_match('/HTTP\/1\.[01] 200/', $response)) {
            throw new Exception("Proxy CONNECT failed: $response");
        }
        
        // Skip headers
        while (($line = fgets($this->socket)) !== false) {
            if (trim($line) === '') break;
        }
        
        // Now enable SSL if needed
        if ($this->ssl) {
            $crypto = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                throw new Exception("SSL handshake failed through proxy");
            }
        }
        
        // Read server greeting
        $greeting = $this->readResponse();
        if (!preg_match('/^\* OK/', $greeting)) {
            throw new Exception("Invalid server greeting: $greeting");
        }
        
        return $this->authenticate();
    }
    
    /**
     * Connect through SOCKS5 proxy
     */
    private function connectSocks5Proxy() {
        $proxyHost = $this->proxy['proxy_host'];
        $proxyPort = $this->proxy['proxy_port'];
        
        // Connect to SOCKS5 proxy
        $this->socket = stream_socket_client(
            "tcp://{$proxyHost}:{$proxyPort}",
            $errno, $errstr, 30
        );
        
        if (!$this->socket) {
            throw new Exception("SOCKS5 proxy connection failed: $errstr ($errno)");
        }
        
        // SOCKS5 handshake
        // 1. Authentication method selection
        $authMethods = pack('CCC', 0x05, 0x01, 0x02); // Version 5, 1 method, Username/Password
        if (empty($this->proxy['proxy_username'])) {
            $authMethods = pack('CCC', 0x05, 0x01, 0x00); // No authentication
        }
        
        fwrite($this->socket, $authMethods);
        $response = fread($this->socket, 2);
        $data = unpack('Cversion/Cmethod', $response);
        
        if ($data['version'] !== 0x05) {
            throw new Exception("Invalid SOCKS5 version");
        }
        
        // 2. Authentication if required
        if ($data['method'] === 0x02) { // Username/Password authentication
            $username = $this->proxy['proxy_username'];
            $password = $this->proxy['proxy_password'];
            $auth = pack('C', 0x01) . pack('C', strlen($username)) . $username . pack('C', strlen($password)) . $password;
            fwrite($this->socket, $auth);
            
            $response = fread($this->socket, 2);
            $data = unpack('Cversion/Cstatus', $response);
            if ($data['status'] !== 0x00) {
                throw new Exception("SOCKS5 authentication failed");
            }
        } elseif ($data['method'] === 0xFF) {
            throw new Exception("SOCKS5 no acceptable authentication methods");
        }
        
        // 3. Connection request
        $serverIp = gethostbyname($this->server);
        $isIp = filter_var($serverIp, FILTER_VALIDATE_IP);
        
        if ($isIp && $serverIp !== $this->server) {
            // IPv4 address
            $request = pack('CCCC', 0x05, 0x01, 0x00, 0x01);
            $request .= inet_pton($serverIp);
        } else {
            // Domain name
            $request = pack('CCCC', 0x05, 0x01, 0x00, 0x03);
            $request .= pack('C', strlen($this->server)) . $this->server;
        }
        $request .= pack('n', $this->port);
        
        fwrite($this->socket, $request);
        
        // Read response
        $response = fread($this->socket, 10);
        $data = unpack('Cversion/Cstatus/Creserved/Catype', $response);
        
        if ($data['status'] !== 0x00) {
            throw new Exception("SOCKS5 connection failed, status: " . $data['status']);
        }
        
        // Now enable SSL if needed
        if ($this->ssl) {
            $crypto = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                throw new Exception("SSL handshake failed through SOCKS5 proxy");
            }
        }
        
        // Read server greeting
        $greeting = $this->readResponse();
        if (!preg_match('/^\* OK/', $greeting)) {
            throw new Exception("Invalid server greeting: $greeting");
        }
        
        return $this->authenticate();
    }
    
    /**
     * Authenticate with IMAP server
     */
    private function authenticate() {
        $tag = $this->getNextTag();
        $command = "$tag LOGIN \"" . $this->escapeString($this->username) . "\" \"" . $this->escapeString($this->password) . "\"\r\n";
        
        fwrite($this->socket, $command);
        $response = $this->readTaggedResponse($tag);
        
        if (preg_match("/^$tag OK/", $response)) {
            $this->authenticated = true;
            return true;
        } else {
            throw new Exception("Authentication failed: $response");
        }
    }
    
    /**
     * Select mailbox (typically INBOX)
     */
    public function selectMailbox($mailbox = 'INBOX') {
        if (!$this->authenticated) {
            throw new Exception("Not authenticated");
        }
        
        $tag = $this->getNextTag();
        $command = "$tag SELECT \"$mailbox\"\r\n";
        
        fwrite($this->socket, $command);
        $response = $this->readTaggedResponse($tag);
        
        if (preg_match("/^$tag OK/", $response)) {
            $this->selectedMailbox = $mailbox;
            return true;
        } else {
            throw new Exception("Mailbox selection failed: $response");
        }
    }
    
    /**
     * Get message count
     */
    public function getMessageCount() {
        if (!$this->selectedMailbox) {
            $this->selectMailbox();
        }
        
        $tag = $this->getNextTag();
        $command = "$tag STATUS INBOX (MESSAGES)\r\n";
        
        fwrite($this->socket, $command);
        $response = $this->readTaggedResponse($tag);
        
        if (preg_match('/\* STATUS INBOX \(MESSAGES (\d+)\)/', $response, $matches)) {
            return (int)$matches[1];
        }
        
        return 0;
    }
    
    /**
     * Fetch latest message
     */
    public function getLatestMessage() {
        if (!$this->selectedMailbox) {
            $this->selectMailbox();
        }
        
        $messageCount = $this->getMessageCount();
        if ($messageCount === 0) {
            return null;
        }
        
        // Fetch headers of the latest message
        $tag = $this->getNextTag();
        $command = "$tag FETCH $messageCount (ENVELOPE BODY[TEXT])\r\n";
        
        fwrite($this->socket, $command);
        $response = $this->readTaggedResponse($tag);
        
        return $this->parseMessage($response);
    }
    
    /**
     * Parse message response
     */
    private function parseMessage($response) {
        $message = [
            'subject' => '',
            'from' => '',
            'from_email' => '',
            'from_name' => '',
            'to' => '',
            'date' => '',
            'body' => '',
            'message_id' => '',
            'size' => 0
        ];
        
        // This is a simplified parser - for production use, you might want a more robust IMAP response parser
        if (preg_match('/ENVELOPE \((.*?)\) BODY\[TEXT\]/s', $response, $matches)) {
            $envelope = $matches[1];
            
            // Parse envelope (simplified)
            $parts = explode('" "', $envelope);
            if (count($parts) >= 2) {
                $message['subject'] = trim($parts[1], '"');
            }
        }
        
        if (preg_match('/BODY\[TEXT\] \{(\d+)\}\r\n(.*?)$/s', $response, $matches)) {
            $message['body'] = trim($matches[2]);
            $message['size'] = (int)$matches[1];
        }
        
        return $message;
    }
    
    /**
     * Close connection
     */
    public function close() {
        if ($this->socket) {
            if ($this->authenticated) {
                $tag = $this->getNextTag();
                fwrite($this->socket, "$tag LOGOUT\r\n");
                $this->readTaggedResponse($tag);
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    /**
     * Read response from server
     */
    private function readResponse() {
        if (!$this->socket) {
            throw new Exception("No connection");
        }
        
        $response = fgets($this->socket, 8192);
        $this->lastResponse = $response;
        return $response;
    }
    
    /**
     * Read tagged response
     */
    private function readTaggedResponse($tag) {
        $response = '';
        while (($line = fgets($this->socket, 8192)) !== false) {
            $response .= $line;
            if (preg_match("/^$tag (OK|NO|BAD)/", $line)) {
                break;
            }
        }
        return $response;
    }
    
    /**
     * Get next command tag
     */
    private function getNextTag() {
        return 'A' . sprintf('%04d', $this->tagCounter++);
    }
    
    /**
     * Escape string for IMAP
     */
    private function escapeString($string) {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $string);
    }
    
    /**
     * Get last error
     */
    public function getLastError() {
        return $this->lastError;
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
                    'message' => 'Connection successful'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $this->lastError ?: 'Connection failed'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>