<?php
/**
 * Direct test of the functionality without API wrapper
 */

chdir('/home/runner/work/9.10/9.10');

require_once 'backend/utils/python_mail_bridge.php';

echo "=== Testing Email Connection API Logic ===\n\n";

try {
    // Connect to database
    $db = new SQLite3('db/mail.sqlite');
    
    // Check if test account exists
    $stmt = $db->prepare('SELECT * FROM mail_accounts WHERE email = ?');
    $stmt->bindValue(1, 'test@gmail.com');
    $result = $stmt->execute();
    $account = $result->fetchArray();
    
    if (!$account) {
        echo "❌ Test account not found in database\n";
        exit(1);
    }
    
    echo "✅ Test account found:\n";
    echo "  Email: " . $account['email'] . "\n";
    echo "  Server: " . $account['server'] . ":" . $account['port'] . "\n";
    echo "  Protocol: " . $account['protocol'] . "\n";
    echo "  SSL: " . ($account['ssl'] ? 'Yes' : 'No') . "\n\n";
    
    // Test Python mail bridge
    echo "=== Testing Python Mail Bridge ===\n";
    $fetcher = new PythonMailFetcher('test@gmail.com');
    
    echo "Testing connection...\n";
    $result = $fetcher->testConnection();
    
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    // Test the response structure that would be returned by API
    $apiResponse = [
        'success' => $result['success'],
        'message' => $result['message'],
        'diagnostics' => $result['diagnostics'] ?? [],
        'error_type' => $result['error_type'] ?? null,
        'account_info' => [
            'email' => $account['email'],
            'server' => $account['server'],
            'port' => $account['port'],
            'protocol' => $account['protocol'],
            'ssl' => (bool) $account['ssl'],
            'remarks' => $account['remarks']
        ]
    ];
    
    echo "\n=== Expected API Response ===\n";
    echo json_encode($apiResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    $db->close();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>