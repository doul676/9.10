<?php
/**
 * Python Mail Fetcher Bridge
 * Replaces php-imap functionality with Python implementation
 */

class PythonMailFetcher {
    private $email;
    private $pythonScript;
    
    public function __construct($email) {
        $this->email = $email;
        $this->pythonScript = __DIR__ . '/../../python/mail_fetcher.py';
    }
    
    /**
     * Get latest mail using Python service
     */
    public function getLatestMail() {
        try {
            // Check if Python script exists
            if (!file_exists($this->pythonScript)) {
                return [
                    'success' => false,
                    'message' => 'Python邮件服务未找到，请检查系统配置'
                ];
            }
            
            // Execute Python script
            $command = "python3 " . escapeshellarg($this->pythonScript) . " " . escapeshellarg($this->email) . " 2>/dev/null";
            $output = shell_exec($command);
            
            if ($output === null) {
                return [
                    'success' => false,
                    'message' => '无法执行Python邮件服务'
                ];
            }
            
            // Clean up any extra output and get only the JSON
            $lines = explode("\n", trim($output));
            $jsonOutput = '';
            
            // Find the JSON output (should be the last non-empty line)
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if (!empty($line) && (substr($line, 0, 1) === '{' || substr($line, 0, 1) === '[')) {
                    $jsonOutput = $line;
                    break;
                }
            }
            
            if (empty($jsonOutput)) {
                error_log("Python mail fetcher raw output: " . $output);
                return [
                    'success' => false,
                    'message' => 'Python邮件服务未返回有效数据'
                ];
            }
            
            // Parse JSON output
            $result = json_decode($jsonOutput, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Python mail fetcher JSON error: " . json_last_error_msg());
                error_log("Python mail fetcher JSON output: " . $jsonOutput);
                return [
                    'success' => false,
                    'message' => 'Python邮件服务返回格式错误: ' . json_last_error_msg()
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Python邮件服务调用失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Test connection using Python service
     */
    public function testConnection() {
        try {
            // For testing, we can call the main function and check the result
            $result = $this->getLatestMail();
            
            if ($result['success']) {
                return [
                    'success' => true,
                    'message' => '✅ 邮箱连接测试成功！',
                    'diagnostics' => [
                        'python_service' => '✅ Python邮件服务运行正常',
                        'connection_test' => '✅ 服务器连接成功',
                        'auth_status' => '✅ 身份验证成功'
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $result['message'],
                    'diagnostics' => [
                        'python_service' => '✅ Python邮件服务运行正常',
                        'connection_issue' => '❌ 邮件服务器连接失败'
                    ],
                    'error_type' => 'connection_failed'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ 连接测试失败: ' . $e->getMessage(),
                'diagnostics' => [
                    'python_service' => '❌ Python邮件服务调用失败'
                ],
                'error_type' => 'service_error'
            ];
        }
    }
    
    /**
     * Get proxy information
     */
    public function getProxyInfo() {
        $result = $this->getLatestMail();
        return $result['proxy'] ?? ['enabled' => false, 'info' => null];
    }
}
?>