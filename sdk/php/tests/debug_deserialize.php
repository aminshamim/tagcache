<?php
declare(strict_types=1);

/**
 * Test deserializeValue directly with the exact value from server
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

// Use reflection to test deserializeValue directly
$reflection = new \ReflectionClass($transport);
$deserializeMethod = $reflection->getMethod('deserializeValue');
$deserializeMethod->setAccessible(true);

echo "Testing deserializeValue directly:\n";
echo "==================================\n\n";

echo "1. Deserializing '__TC_NULL__':\n";
$result = $deserializeMethod->invoke($transport, '__TC_NULL__');
echo "Result: " . var_export($result, true) . " (type: " . gettype($result) . ")\n";
echo "Is null? " . (is_null($result) ? "YES" : "NO") . "\n\n";

echo "2. Testing the exact value from the server response:\n";
// From the debug output, the server returns 'value' => '__TC_NULL__'
$serverValue = '__TC_NULL__';
$deserialized = $deserializeMethod->invoke($transport, $serverValue);
echo "Server value: " . var_export($serverValue, true) . "\n";
echo "Deserialized: " . var_export($deserialized, true) . " (type: " . gettype($deserialized) . ")\n";
