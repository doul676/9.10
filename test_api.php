<?php
// Simple test of the new API
$_SERVER['REQUEST_METHOD'] = 'POST';
$input = json_encode(['email' => 'test@example.com']);

// Simulate the request
$old_input = file_get_contents('php://input');
$GLOBALS['test_input'] = $input;

// Mock file_get_contents for testing
function file_get_contents($filename) {
    if ($filename === 'php://input') {
        return $GLOBALS['test_input'];
    }
    return call_user_func_array('\\file_get_contents', func_get_args());
}

ob_start();
include './backend/api/get_mail.php';
$output = ob_get_clean();

echo "API Output:\n";
echo $output;
?>