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
            
            // Execute Python script with improved error handling
            $command = "python3 " . escapeshellarg($this->pythonScript) . " " . escapeshellarg($this->email) . " 2>&1";
            $output = shell_exec($command);
            
            if ($output === null || trim($output) === '') {
                return [
                    'success' => false,
                    'message' => '无法执行Python邮件服务，请检查Python环境配置'
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
                // Log the raw output for debugging
                error_log("Python mail fetcher raw output: " . $output);
                
                // Try to extract error information from the output
                $errorInfo = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line) && !preg_match('/^INFO:/', $line)) {
                        $errorInfo[] = $line;
                    }
                }
                
                $errorMessage = 'Python邮件服务未返回有效数据';
                if (!empty($errorInfo)) {
                    $errorMessage .= '，错误信息: ' . implode(' ', $errorInfo);
                }
                
                return [
                    'success' => false,
                    'message' => $errorMessage
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
            // Check if Python script exists
            if (!file_exists($this->pythonScript)) {
                return [
                    'success' => false,
                    'message' => 'Python邮件服务未找到，请检查系统配置',
                    'diagnostics' => [
                        'python_service' => '❌ Python邮件服务文件不存在'
                    ],
                    'error_type' => 'service_missing'
                ];
            }
            
            // Execute Python script with test flag for connection testing
            $command = "python3 " . escapeshellarg($this->pythonScript) . " " . escapeshellarg($this->email) . " --test-connection 2>&1";
            $output = shell_exec($command);
            
            if ($output === null || trim($output) === '') {
                return [
                    'success' => false,
                    'message' => '无法执行Python邮件服务，请检查Python环境配置',
                    'diagnostics' => [
                        'python_service' => '❌ Python邮件服务执行失败',
                        'execution_error' => '系统无法启动Python进程'
                    ],
                    'error_type' => 'execution_failed'
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
                error_log("Python mail fetcher test output: " . $output);
                
                // Try to extract useful error information from the output
                $errorInfo = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line) && !preg_match('/^INFO:/', $line)) {
                        $errorInfo[] = $line;
                    }
                }
                
                $errorMessage = 'Python邮件服务未返回有效数据';
                if (!empty($errorInfo)) {
                    $errorMessage .= '，错误信息: ' . implode(' ', $errorInfo);
                }
                
                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'diagnostics' => [
                        'python_service' => '❌ Python服务响应格式错误',
                        'raw_output' => implode(' ', $errorInfo) ?: 'No error details available'
                    ],
                    'error_type' => 'invalid_response'
                ];
            }
            
            // Parse JSON output
            $result = json_decode($jsonOutput, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Python mail fetcher JSON error: " . json_last_error_msg());
                error_log("Python mail fetcher JSON output: " . $jsonOutput);
                return [
                    'success' => false,
                    'message' => 'Python邮件服务返回格式错误: ' . json_last_error_msg(),
                    'diagnostics' => [
                        'python_service' => '✅ Python服务运行正常',
                        'json_error' => json_last_error_msg(),
                        'raw_response' => substr($jsonOutput, 0, 200) . '...'
                    ],
                    'error_type' => 'json_error'
                ];
            }
            
            // Add Python service status to diagnostics
            if (isset($result['diagnostics'])) {
                $result['diagnostics']['python_service'] = '✅ Python邮件服务运行正常';
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ 连接测试失败: ' . $e->getMessage(),
                'diagnostics' => [
                    'python_service' => '❌ Python邮件服务调用失败',
                    'exception_details' => $e->getMessage()
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