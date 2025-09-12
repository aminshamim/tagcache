<?php
declare(strict_types=1);

/**
 * Debug what's actually sent to the server
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

// Use reflection to test serialization step by step
$reflection = new \ReflectionClass($transport);
$serializeMethod = $reflection->getMethod('serializeValue');
$serializeMethod->setAccessible(true);

echo "Step-by-step debug:\n";
echo "==================\n\n";

echo "1. Testing what serializeValue returns for null:\n";
$serializedNull = $serializeMethod->invoke($transport, null);
echo "serializeValue(null) = " . var_export($serializedNull, true) . "\n\n";

echo "2. Making a direct request to test JSON encoding:\n";
$requestMethod = $reflection->getMethod('request');
$requestMethod->setAccessible(true);

// Store directly using request method
$payload = [
    'value' => $serializedNull,
    'ttl_ms' => 60000,
    'tags' => []
];
echo "Payload being sent: " . var_export($payload, true) . "\n";

$putResponse = $requestMethod->invoke($transport, 'PUT', '/keys/debug_step:null', $payload);
echo "PUT response: " . var_export($putResponse, true) . "\n\n";

echo "3. Getting it back:\n";
$getResponse = $requestMethod->invoke($transport, 'GET', '/keys/debug_step:null');
echo "GET response: " . var_export($getResponse, true) . "\n\n";

// Cleanup
$transport->delete('debug_step:null');
