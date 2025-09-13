<?php
/**
 * Test API endpoint with proper simulation
 */

// Change to the API directory to test
chdir('backend/api');

// Mock the request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// Create a temporary stdin with JSON data
$json_data = json_encode(['email' => 'test@gmail.com']);
$temp_file = tempnam(sys_get_temp_dir(), 'json_input');
file_put_contents($temp_file, $json_data);

// Override the input stream
$original_stdin = STDIN;
define('STDIN', fopen($temp_file, 'r'));

// Mock php://input
stream_wrapper_unregister("php");
stream_wrapper_register("php", "MockPhpInputStream");

class MockPhpInputStream {
    private $position = 0;
    private $data;
    
    public function __construct() {
        $this->data = json_encode(['email' => 'test@gmail.com']);
    }
    
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }
    
    public function stream_read($count) {
        $ret = substr($this->data, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }
    
    public function stream_eof() {
        return $this->position >= strlen($this->data);
    }
    
    public function stream_stat() {
        return array();
    }
}

// Capture output
ob_start();

try {
    include 'test_connection.php';
    $output = ob_get_clean();
    
    echo "=== API Test Results ===\n";
    echo $output . "\n";
    
    // Parse and format the response
    $response = json_decode($output, true);
    if ($response) {
        echo "\n=== Formatted Results ===\n";
        echo "Success: " . ($response['success'] ? 'Yes' : 'No') . "\n";
        echo "Message: " . $response['message'] . "\n";
        
        if (isset($response['diagnostics'])) {
            echo "\nDiagnostics:\n";
            foreach ($response['diagnostics'] as $key => $value) {
                echo "  $key: $value\n";
            }
        }
        
        if (isset($response['account_info'])) {
            echo "\nAccount Info:\n";
            foreach ($response['account_info'] as $key => $value) {
                echo "  $key: " . (is_bool($value) ? ($value ? 'true' : 'false') : $value) . "\n";
            }
        }
    }
} catch (Exception $e) {
    ob_end_clean();
    echo "Error: " . $e->getMessage() . "\n";
}

// Cleanup
unlink($temp_file);
?>