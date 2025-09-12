<?php
declare(strict_types=1);

/**
 * Test the exact get method path
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Transport\HttpTransport;
use TagCache\Config;

$config = new Config([
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 5000,
        'serializer' => 'native',
        'auto_serialize' => true,
    ],
    'auth' => [
        'username' => 'admin',
        'password' => 'password',
    ],
]);

$transport = new HttpTransport($config);

echo "Testing the exact get() method path:\n";
echo "===================================\n\n";

// First, put a null value
echo "1. Storing null value:\n";
$transport->put('debug_get:null', null);

echo "2. Using transport->get() directly:\n";
$result = $transport->get('debug_get:null');
echo "transport->get() result: " . var_export($result, true) . "\n\n";

echo "3. Checking the value specifically:\n";
if (isset($result['value'])) {
    echo "Value exists: " . var_export($result['value'], true) . " (type: " . gettype($result['value']) . ")\n";
    echo "Is null? " . (is_null($result['value']) ? "YES" : "NO") . "\n";
} else {
    echo "No 'value' key found in result\n";
}

// Cleanup
$transport->delete('debug_get:null');
