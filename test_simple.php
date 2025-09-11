<?php
// Direct test of API logic
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = []; // Ensure POST is empty for JSON test

// Simulate JSON input
$jsonInput = '{"email":"test@example.com"}';
$tempFile = tempnam(sys_get_temp_dir(), 'api_test');
file_put_contents($tempFile, $jsonInput);

// Override php://input for testing
ini_set('auto_prepend_file', '');

echo "Testing API with non-existent email...\n";

// Test the logic manually
$input = json_decode($jsonInput, true);
$email = $input['email'] ?? '';

if (empty($email)) {
    echo json_encode([
        'success' => false,
        'message' => '请输入邮箱地址'
    ]);
    exit();
}

echo "Email: $email\n";

// Test database connection
try {
    $db = new SQLite3('./db/mail.sqlite');
    $stmt = $db->prepare('SELECT * FROM mail_accounts WHERE email = ?');
    $stmt->bindValue(1, $email);
    $result = $stmt->execute();
    $account = $result->fetchArray();
    
    if (!$account) {
        echo json_encode([
            'success' => false,
            'message' => '邮箱账号不存在，请联系管理员添加'
        ]);
    } else {
        echo "Found account: " . print_r($account, true);
    }
    $db->close();
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?>