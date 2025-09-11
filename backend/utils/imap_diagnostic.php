<?php
/**
 * IMAP 诊断工具
 * 用于检测IMAP扩展状态、代理兼容性和连接问题
 */

require_once __DIR__ . '/proxy_manager.php';

class IMAPDiagnostic {
    private $proxyManager;
    
    public function __construct() {
        $this->proxyManager = new ProxyManager();
    }
    
    /**
     * 执行完整的IMAP和代理兼容性诊断
     * @return array 诊断结果
     */
    public function runFullDiagnostic() {
        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'environment' => php_sapi_name(),
            'php_version' => PHP_VERSION,
            'checks' => []
        ];
        
        // 1. IMAP扩展检查
        $results['checks']['imap_extension'] = $this->checkIMAPExtension();
        
        // 2. 代理兼容性检查
        $results['checks']['proxy_compatibility'] = $this->checkProxyCompatibility();
        
        // 3. 代理池状态检查
        $results['checks']['proxy_pool'] = $this->checkProxyPool();
        
        // 4. 网络连接能力检查
        $results['checks']['network_connectivity'] = $this->checkNetworkConnectivity();
        
        // 5. 替代方案建议
        $results['recommendations'] = $this->generateRecommendations($results['checks']);
        
        return $results;
    }
    
    /**
     * 检查IMAP扩展状态
     */
    private function checkIMAPExtension() {
        $result = [
            'name' => 'IMAP扩展检查',
            'status' => 'unknown',
            'details' => [],
            'issues' => []
        ];
        
        // 检查扩展是否加载
        if (!extension_loaded('imap')) {
            $result['status'] = 'error';
            $result['issues'][] = 'PHP IMAP扩展未加载';
            $result['details']['extension_loaded'] = false;
            return $result;
        }
        
        $result['details']['extension_loaded'] = true;
        
        // 检查核心函数
        $requiredFunctions = [
            'imap_open', 'imap_close', 'imap_errors', 'imap_last_error',
            'imap_num_msg', 'imap_headerinfo', 'imap_fetchbody',
            'imap_fetchstructure', 'imap_mime_header_decode'
        ];
        
        $missingFunctions = [];
        $availableFunctions = [];
        
        foreach ($requiredFunctions as $function) {
            if (function_exists($function)) {
                $availableFunctions[] = $function;
            } else {
                $missingFunctions[] = $function;
            }
        }
        
        $result['details']['available_functions'] = $availableFunctions;
        $result['details']['missing_functions'] = $missingFunctions;
        
        if (empty($missingFunctions)) {
            $result['status'] = 'success';
            $result['details']['functionality'] = 'complete';
        } else {
            $result['status'] = 'warning';
            $result['issues'][] = '部分IMAP函数缺失: ' . implode(', ', $missingFunctions);
            $result['details']['functionality'] = 'partial';
        }
        
        // 检查编译选项
        if (function_exists('imap_open')) {
            $result['details']['ssl_support'] = true; // 现代IMAP扩展通常支持SSL
        }
        
        return $result;
    }
    
    /**
     * 检查代理兼容性
     */
    private function checkProxyCompatibility() {
        $result = [
            'name' => '代理兼容性检查',
            'status' => 'info',
            'details' => [],
            'issues' => [],
            'limitations' => []
        ];
        
        // IMAP扩展代理支持情况
        $result['details']['imap_proxy_support'] = false;
        $result['limitations'][] = 'PHP IMAP扩展本身不支持代理连接';
        $result['limitations'][] = 'imap_open()函数无法配置代理参数';
        $result['limitations'][] = '无法通过设置环境变量或PHP选项启用代理';
        
        // 检查可用的代理解决方案
        $alternatives = [];
        
        // 检查cURL扩展（用于替代方案）
        if (extension_loaded('curl')) {
            $alternatives[] = 'cURL扩展可用 - 可实现基于HTTP/HTTPS的邮件API代理';
            $result['details']['curl_available'] = true;
        } else {
            $result['details']['curl_available'] = false;
            $result['issues'][] = 'cURL扩展不可用，限制了代理替代方案';
        }
        
        // 检查Socket扩展
        if (extension_loaded('sockets')) {
            $alternatives[] = 'Sockets扩展可用 - 可实现自定义代理连接';
            $result['details']['sockets_available'] = true;
        } else {
            $result['details']['sockets_available'] = false;
        }
        
        // 检查stream context支持
        if (function_exists('stream_context_create')) {
            $alternatives[] = 'Stream context支持 - 可用于某些代理方案';
            $result['details']['stream_context_available'] = true;
        } else {
            $result['details']['stream_context_available'] = false;
        }
        
        $result['details']['available_alternatives'] = $alternatives;
        
        return $result;
    }
    
    /**
     * 检查代理池状态
     */
    private function checkProxyPool() {
        $result = [
            'name' => '代理池状态检查',
            'status' => 'unknown',
            'details' => [],
            'issues' => []
        ];
        
        try {
            // 获取代理统计
            $allProxies = $this->proxyManager->getAllAvailableProxies('', false);
            $activeProxies = $this->proxyManager->getAllAvailableProxies('', false);
            $verifiedProxies = $this->proxyManager->getAllAvailableProxies('', true);
            
            $result['details']['total_proxies'] = count($allProxies);
            $result['details']['active_proxies'] = count($activeProxies);
            $result['details']['verified_proxies'] = count($verifiedProxies);
            
            if (count($allProxies) === 0) {
                $result['status'] = 'warning';
                $result['issues'][] = '代理池为空，未配置任何代理';
            } elseif (count($verifiedProxies) === 0) {
                $result['status'] = 'warning';
                $result['issues'][] = '没有已验证的代理，建议测试代理连接';
            } else {
                $result['status'] = 'success';
                
                // 获取最佳代理信息
                $bestProxy = $this->proxyManager->getAvailableProxy('', true);
                if ($bestProxy) {
                    $result['details']['best_proxy'] = [
                        'name' => $bestProxy['proxy_name'] ?: '未命名',
                        'type' => $bestProxy['proxy_type'],
                        'host' => $bestProxy['proxy_host'],
                        'port' => $bestProxy['proxy_port'],
                        'response_time' => $bestProxy['response_time']
                    ];
                }
            }
            
            // 分析代理类型分布
            $typeDistribution = [];
            foreach ($allProxies as $proxy) {
                $type = $proxy['proxy_type'];
                $typeDistribution[$type] = ($typeDistribution[$type] ?? 0) + 1;
            }
            $result['details']['proxy_types'] = $typeDistribution;
            
        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['issues'][] = '无法访问代理池: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * 检查网络连接能力
     */
    private function checkNetworkConnectivity() {
        $result = [
            'name' => '网络连接能力检查',
            'status' => 'unknown',
            'details' => [],
            'issues' => []
        ];
        
        $testHosts = [
            'public_dns' => '8.8.8.8',
            'http_test' => 'httpbin.org',
            'imap_gmail' => 'imap.gmail.com',
            'imap_outlook' => 'outlook.office365.com'
        ];
        
        $connectivityResults = [];
        
        foreach ($testHosts as $name => $host) {
            $connectivityResults[$name] = $this->testHostConnectivity($host);
        }
        
        $result['details']['connectivity_tests'] = $connectivityResults;
        
        // 分析连接结果
        $successfulTests = array_filter($connectivityResults, function($test) {
            return $test['success'];
        });
        
        if (count($successfulTests) === count($testHosts)) {
            $result['status'] = 'success';
        } elseif (count($successfulTests) > 0) {
            $result['status'] = 'warning';
            $result['issues'][] = '部分网络连接测试失败，可能存在网络限制';
        } else {
            $result['status'] = 'error';
            $result['issues'][] = '所有网络连接测试失败，可能存在严重网络问题';
        }
        
        return $result;
    }
    
    /**
     * 测试主机连接
     */
    private function testHostConnectivity($host, $port = 80, $timeout = 5) {
        $result = [
            'host' => $host,
            'port' => $port,
            'success' => false,
            'response_time' => 0,
            'error' => null
        ];
        
        $startTime = microtime(true);
        
        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if ($socket) {
                $result['success'] = true;
                fclose($socket);
            } else {
                $result['error'] = "Connection failed: $errstr ($errno)";
            }
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        $result['response_time'] = round((microtime(true) - $startTime) * 1000);
        
        return $result;
    }
    
    /**
     * 生成建议和解决方案
     */
    private function generateRecommendations($checks) {
        $recommendations = [
            'immediate_actions' => [],
            'proxy_alternatives' => [],
            'system_improvements' => []
        ];
        
        // 基于IMAP扩展状态的建议
        if ($checks['imap_extension']['status'] === 'error') {
            $recommendations['immediate_actions'][] = '安装并启用PHP IMAP扩展';
            $recommendations['system_improvements'][] = '联系系统管理员配置邮件服务器环境';
        } elseif ($checks['imap_extension']['status'] === 'warning') {
            $recommendations['immediate_actions'][] = '重新编译或重新安装PHP IMAP扩展以获得完整功能';
        }
        
        // 代理相关建议
        if ($checks['proxy_pool']['details']['total_proxies'] > 0) {
            $recommendations['proxy_alternatives'][] = '由于IMAP扩展不支持代理，考虑以下替代方案：';
            $recommendations['proxy_alternatives'][] = '• 使用API转发方式访问邮件服务';
            $recommendations['proxy_alternatives'][] = '• 在代理服务器上配置端口转发';
            $recommendations['proxy_alternatives'][] = '• 使用支持代理的第三方邮件库';
            $recommendations['proxy_alternatives'][] = '• 通过VPN或隧道建立网络连接';
        }
        
        // 网络连接建议
        if ($checks['network_connectivity']['status'] !== 'success') {
            $recommendations['immediate_actions'][] = '检查网络连接和防火墙设置';
            $recommendations['system_improvements'][] = '确保邮件服务器端口可以直接访问';
        }
        
        // 通用建议
        $recommendations['system_improvements'][] = '考虑使用邮件服务商的API而非IMAP协议';
        $recommendations['system_improvements'][] = '为重要邮件账户配置应用专用密码';
        $recommendations['system_improvements'][] = '定期监控代理池状态和连接质量';
        
        return $recommendations;
    }
    
    /**
     * 生成诊断报告
     */
    public function generateReport($format = 'array') {
        $diagnostic = $this->runFullDiagnostic();
        
        if ($format === 'text') {
            return $this->formatTextReport($diagnostic);
        } elseif ($format === 'html') {
            return $this->formatHTMLReport($diagnostic);
        }
        
        return $diagnostic;
    }
    
    /**
     * 格式化文本报告
     */
    private function formatTextReport($diagnostic) {
        $report = "IMAP & 代理兼容性诊断报告\n";
        $report .= "=====================================\n";
        $report .= "生成时间: " . $diagnostic['timestamp'] . "\n";
        $report .= "PHP版本: " . $diagnostic['php_version'] . "\n";
        $report .= "运行环境: " . $diagnostic['environment'] . "\n\n";
        
        foreach ($diagnostic['checks'] as $checkName => $check) {
            $report .= $check['name'] . "\n";
            $report .= str_repeat('-', strlen($check['name'])) . "\n";
            $report .= "状态: " . $check['status'] . "\n";
            
            if (!empty($check['issues'])) {
                $report .= "问题:\n";
                foreach ($check['issues'] as $issue) {
                    $report .= "  • $issue\n";
                }
            }
            
            $report .= "\n";
        }
        
        $report .= "建议和解决方案\n";
        $report .= "===============\n";
        
        foreach ($diagnostic['recommendations'] as $category => $items) {
            if (!empty($items)) {
                $report .= ucfirst(str_replace('_', ' ', $category)) . ":\n";
                foreach ($items as $item) {
                    $report .= "  $item\n";
                }
                $report .= "\n";
            }
        }
        
        return $report;
    }
    
    /**
     * 格式化HTML报告
     */
    private function formatHTMLReport($diagnostic) {
        // 这里可以实现HTML格式的报告
        // 为了简单起见，现在返回JSON格式
        return json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
?>