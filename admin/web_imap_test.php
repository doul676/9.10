<?php
/**
 * Web environment IMAP test
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== Web Environment IMAP Test ===\n\n";

// Test basic extension loading
echo "1. Extension Status:\n";
echo "   extension_loaded('imap'): " . (extension_loaded('imap') ? 'YES' : 'NO') . "\n";

// Test all IMAP functions mentioned in the problem
$functions_to_test = [
    'imap_open',
    'imap_close', 
    'imap_errors',
    'imap_last_error',
    'imap_num_msg'
];

echo "\n2. Function Availability:\n";
foreach ($functions_to_test as $func) {
    $available = function_exists($func);
    echo "   $func: " . ($available ? 'YES' : 'NO') . "\n";
}

// Test environment info
echo "\n3. Environment Info:\n";
echo "   PHP SAPI: " . php_sapi_name() . "\n";
echo "   PHP Version: " . phpversion() . "\n";
echo "   SERVER_SOFTWARE: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";

echo "\n=== Test Complete ===\n";
?>