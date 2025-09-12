<?php
declare(strict_types=1);

/**
 * Debug test to see what fields the server accepts
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

// Use reflection to access the private request method
$reflection = new \ReflectionClass($transport);
$requestMethod = $reflection->getMethod('request');
$requestMethod->setAccessible(true);

echo "Testing what fields the server accepts...\n";
echo "=======================================\n\n";

// Test putting with extra metadata
$payload = [
    'key' => 'debug:metadata',
    'value' => 'test_value',
    'ttl_ms' => 60000,
    'tags' => [],
    '_tc_type' => 'string',
    '_tc_serialized' => false,
    '_tc_serializer' => 'native',
    'custom_field' => 'custom_value'
];

echo "Sending payload:\n";
var_dump($payload);

try {
    $putResult = $requestMethod->invoke($transport, 'PUT', '/keys/debug:metadata', $payload);
    echo "\nPUT response:\n";
    var_dump($putResult);
} catch (\Exception $e) {
    echo "PUT Error: " . $e->getMessage() . "\n";
}

echo "\nRetrieving the key:\n";
try {
    $getResult = $requestMethod->invoke($transport, 'GET', '/keys/debug:metadata');
    echo "GET response:\n";
    var_dump($getResult);
} catch (\Exception $e) {
    echo "GET Error: " . $e->getMessage() . "\n";
}

// Cleanup
$transport->delete('debug:metadata');
