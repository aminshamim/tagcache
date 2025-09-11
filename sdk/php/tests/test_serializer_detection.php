<?php
declare(strict_types=1);

/**
 * Test serializer availability detection and fallback
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Transport\HttpTransport;
use TagCache\Config;

echo "Testing Serializer Availability and Fallback\n";
echo "============================================\n\n";

// Test different serializer preferences
$serializers = ['igbinary', 'msgpack', 'native', 'invalid'];

foreach ($serializers as $preferred) {
    echo "Testing preferred serializer: '$preferred'\n";
    
    $config = new Config([
        'mode' => 'http',
        'http' => [
            'base_url' => 'http://localhost:8080',
            'timeout_ms' => 5000,
            'serializer' => $preferred,
            'auto_serialize' => true,
        ],
        'auth' => [
            'username' => 'admin',
            'password' => 'password',
        ],
    ]);
    
    $transport = new HttpTransport($config);
    
    // Use reflection to check what serializer was actually chosen
    $reflection = new \ReflectionClass($transport);
    $serializerProperty = $reflection->getProperty('serializer');
    $serializerProperty->setAccessible(true);
    $actualSerializer = $serializerProperty->getValue($transport);
    
    echo "  Requested: $preferred\n";
    echo "  Actual: $actualSerializer\n";
    
    // Check availability of the chosen serializer
    $available = match($actualSerializer) {
        'igbinary' => function_exists('igbinary_serialize') && function_exists('igbinary_unserialize'),
        'msgpack' => function_exists('msgpack_pack') && function_exists('msgpack_unpack'),
        'native' => true,
        default => false
    };
    
    echo "  Available: " . ($available ? "YES" : "NO") . "\n";
    
    // Test a quick serialization round-trip
    $testData = ['test' => 'data', 'number' => 42];
    
    try {
        $serializeMethod = $reflection->getMethod('performSerialization');
        $deserializeMethod = $reflection->getMethod('performDeserialization');
        $serializeMethod->setAccessible(true);
        $deserializeMethod->setAccessible(true);
        
        $serialized = $serializeMethod->invoke($transport, $testData);
        $deserialized = $deserializeMethod->invoke($transport, $serialized);
        
        $success = $testData === $deserialized;
        echo "  Round-trip test: " . ($success ? "PASS" : "FAIL") . "\n";
        
        if (!$success) {
            echo "    Original: " . var_export($testData, true) . "\n";
            echo "    Result: " . var_export($deserialized, true) . "\n";
        }
    } catch (\Throwable $e) {
        echo "  Round-trip test: ERROR - " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

// Check which extensions are actually available
echo "Extension Availability:\n";
echo "======================\n";
echo "igbinary extension: " . (extension_loaded('igbinary') ? "LOADED" : "NOT LOADED") . "\n";
echo "msgpack extension: " . (extension_loaded('msgpack') ? "LOADED" : "NOT LOADED") . "\n";
echo "igbinary_serialize function: " . (function_exists('igbinary_serialize') ? "AVAILABLE" : "NOT AVAILABLE") . "\n";
echo "igbinary_unserialize function: " . (function_exists('igbinary_unserialize') ? "AVAILABLE" : "NOT AVAILABLE") . "\n";
echo "msgpack_pack function: " . (function_exists('msgpack_pack') ? "AVAILABLE" : "NOT AVAILABLE") . "\n";
echo "msgpack_unpack function: " . (function_exists('msgpack_unpack') ? "AVAILABLE" : "NOT AVAILABLE") . "\n";
