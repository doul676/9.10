<?php
/**
 * Test script for PHP mail bridge functionality
 */

require_once 'backend/utils/python_mail_bridge.php';

// Test the Python mail bridge
echo "=== Testing Python Mail Bridge ===\n";

$fetcher = new PythonMailFetcher('test@gmail.com');

echo "\n1. Testing Connection Test...\n";
$result = $fetcher->testConnection();

echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
echo "Message: " . $result['message'] . "\n";

if (isset($result['diagnostics'])) {
    echo "\nDiagnostics:\n";
    foreach ($result['diagnostics'] as $key => $value) {
        echo "  $key: $value\n";
    }
}

if (isset($result['error_type'])) {
    echo "\nError Type: " . $result['error_type'] . "\n";
}

echo "\n2. Testing Mail Fetching...\n";
$mailResult = $fetcher->getLatestMail();

echo "Success: " . ($mailResult['success'] ? 'Yes' : 'No') . "\n";
echo "Message: " . $mailResult['message'] . "\n";

if (isset($mailResult['proxy'])) {
    echo "\nProxy Info:\n";
    echo "  Enabled: " . ($mailResult['proxy']['enabled'] ? 'Yes' : 'No') . "\n";
    if ($mailResult['proxy']['enabled'] && $mailResult['proxy']['info']) {
        echo "  Type: " . $mailResult['proxy']['info']['type'] . "\n";
        echo "  Host: " . $mailResult['proxy']['info']['host'] . "\n";
        echo "  Port: " . $mailResult['proxy']['info']['port'] . "\n";
        echo "  Name: " . $mailResult['proxy']['info']['name'] . "\n";
    }
}

echo "\n=== Test Complete ===\n";
?>