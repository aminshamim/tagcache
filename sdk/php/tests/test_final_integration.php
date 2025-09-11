<?php
declare(strict_types=1);

/**
 * Final Integration Test for TagCache PHP SDK
 * Tests all serializers with real TagCache operations
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

echo "TagCache PHP SDK Final Integration Test\n";
echo "======================================\n\n";

// Helper function for deep value comparison
function valuesEqual($expected, $actual): bool {
    if (is_object($expected) && is_object($actual)) {
        return serialize($expected) === serialize($actual);
    }
    return $expected === $actual;
}

// Test data - comprehensive coverage
$testCases = [
    'string' => 'Hello TagCache with Guzzle!',
    'integer' => 42,
    'float' => 3.14159,
    'boolean_true' => true,
    'boolean_false' => false,
    'null_value' => null,
    'array_simple' => [1, 2, 3, 4, 5],
    'array_associative' => ['name' => 'John', 'age' => 30, 'city' => 'New York'],
    'array_nested' => [
        'user' => [
            'id' => 123,
            'profile' => [
                'name' => 'Jane',
                'settings' => ['theme' => 'dark', 'notifications' => true]
            ]
        ],
        'metadata' => ['created' => '2024-01-15', 'tags' => ['php', 'cache', 'performance']]
    ],
    'object' => (object)['property' => 'value', 'nested' => (object)['deep' => 'data']]
];

// Test each serializer
$serializers = ['igbinary', 'msgpack', 'native'];

foreach ($serializers as $serializer) {
    echo "Testing with $serializer serializer:\n";
    echo str_repeat('-', 30 + strlen($serializer)) . "\n";
    
    $config = new Config([
        'mode' => 'http',
        'http' => [
            'base_url' => 'http://localhost:8080',
            'timeout_ms' => 5000,
            'serializer' => $serializer,
            'auto_serialize' => true,
        ],
        'auth' => [
            'username' => 'admin',
            'password' => 'password',
        ],
    ]);
    
    $client = new Client($config);
    $allPassed = true;
    
    // Individual operations
    foreach ($testCases as $key => $value) {
        $cacheKey = "test_{$serializer}_{$key}";
        
        try {
            // Put
            $client->put($cacheKey, $value, null, ['tag1', 'tag2', $serializer]);
            
            // Get
            $retrieved = $client->get($cacheKey);
            
            // Compare
            $matches = valuesEqual($value, $retrieved);
            
            echo "  $key: " . ($matches ? 'PASS' : 'FAIL');
            if (!$matches) {
                echo " (Expected: " . var_export($value, true) . ", Got: " . var_export($retrieved, true) . ")";
                $allPassed = false;
            }
            echo "\n";
            
        } catch (\Exception $e) {
            echo "  $key: ERROR - " . $e->getMessage() . "\n";
            $allPassed = false;
        }
    }
    
    // Bulk operations
    echo "  Bulk operations:\n";
    try {
        $bulkKeys = [];
        $bulkData = [];
        foreach (['bulk_string', 'bulk_array', 'bulk_object'] as $i => $bulkKey) {
            $key = "bulk_{$serializer}_{$bulkKey}";
            $value = $testCases[array_keys($testCases)[$i]]; // Use first few test cases
            $bulkKeys[] = $key;
            $bulkData[$key] = $value;
            $client->put($key, $value, null, ['bulk', $serializer]);
        }
        
        $bulkResult = $client->bulkGet($bulkKeys);
        $bulkMatches = true;
        
        foreach ($bulkData as $key => $expectedValue) {
            if (!array_key_exists($key, $bulkResult) || !valuesEqual($expectedValue, $bulkResult[$key])) {
                $bulkMatches = false;
                break;
            }
        }
        
        echo "    Bulk get: " . ($bulkMatches ? 'PASS' : 'FAIL') . "\n";
        if (!$bulkMatches) {
            $allPassed = false;
        }
        
    } catch (\Exception $e) {
        echo "    Bulk operations: ERROR - " . $e->getMessage() . "\n";
        $allPassed = false;
    }
    
    echo "  Overall: " . ($allPassed ? '✅ ALL TESTS PASSED' : '❌ SOME TESTS FAILED') . "\n\n";
}

echo "Integration test complete!\n";
