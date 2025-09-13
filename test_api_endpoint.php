<?php
/**
 * Test the API endpoint
 */

// Simulate a POST request to the test_connection API
$_SERVER['REQUEST_METHOD'] = 'POST';

// Capture the output
ob_start();

// Simulate POST data
$_POST = [];
file_put_contents('php://input', json_encode(['email' => 'test@gmail.com']));

// Include the API endpoint
include 'backend/api/test_connection.php';

$output = ob_get_clean();

echo "=== Test Connection API Response ===\n";
echo $output;
echo "\n=== End Response ===\n";

// Parse and display formatted response
$response = json_decode($output, true);
if ($response) {
    echo "\nFormatted Response:\n";
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
            echo "  $key: $value\n";
        }
    }
}
?>