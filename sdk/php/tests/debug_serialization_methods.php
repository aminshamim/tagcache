<?php
declare(strict_types=1);

/**
 * Debug the serialization process step by step
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

// Use reflection to test the serialization methods directly
$reflection = new \ReflectionClass($transport);
$serializeMethod = $reflection->getMethod('serializeValue');
$deserializeMethod = $reflection->getMethod('deserializeValue');
$serializeMethod->setAccessible(true);
$deserializeMethod->setAccessible(true);

echo "Testing serialization methods directly:\n";
echo "=====================================\n\n";

// Test null
echo "1. Testing null:\n";
$serialized = $serializeMethod->invoke($transport, null);
echo "Serialized: " . var_export($serialized, true) . "\n";
$deserialized = $deserializeMethod->invoke($transport, $serialized);
echo "Deserialized: " . var_export($deserialized, true) . " (type: " . gettype($deserialized) . ")\n";
echo "Equal? " . (null === $deserialized ? "YES" : "NO") . "\n\n";

// Test object
echo "2. Testing object:\n";
$obj = (object)['id' => 123, 'name' => 'test'];
$serialized = $serializeMethod->invoke($transport, $obj);
echo "Serialized: " . var_export($serialized, true) . "\n";
$deserialized = $deserializeMethod->invoke($transport, $serialized);
echo "Deserialized: " . var_export($deserialized, true) . " (type: " . gettype($deserialized) . ")\n";
echo "Equal? " . ($obj == $deserialized ? "YES" : "NO") . "\n";
echo "Identical? " . ($obj === $deserialized ? "YES" : "NO") . "\n\n";

// Test array
echo "3. Testing array:\n";
$arr = ['foo', 'bar', 'baz'];
$serialized = $serializeMethod->invoke($transport, $arr);
echo "Serialized: " . var_export($serialized, true) . "\n";
$deserialized = $deserializeMethod->invoke($transport, $serialized);
echo "Deserialized: " . var_export($deserialized, true) . " (type: " . gettype($deserialized) . ")\n";
echo "Equal? " . ($arr === $deserialized ? "YES" : "NO") . "\n\n";
