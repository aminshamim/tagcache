<?php
declare(strict_types=1);

/**
 * Integration test to verify serialization works end-to-end with TagCache server
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

$config = new Config([
    'mode' => 'http', // Force HTTP transport to use our serialization
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

echo "Testing PHP Object Serialization with TagCache\n";
echo "============================================\n\n";

// Create client with serialization enabled
$client = new Client($config);

// Test data with various PHP types
$testData = [
    'string' => 'Hello, World!',
    'integer' => 42,
    'float' => 3.14159,
    'boolean_true' => true,
    'boolean_false' => false,
    'null_value' => null,
    'simple_array' => ['foo', 'bar', 'baz'],
    'associative_array' => ['name' => 'John', 'age' => 30, 'active' => true],
    'nested_array' => [
        'user' => ['name' => 'Jane', 'settings' => ['theme' => 'dark']],
        'counts' => [10, 20, 30]
    ],
    'object' => (object)[
        'id' => 123,
        'name' => 'Test Object',
        'data' => ['key' => 'value']
    ]
];

echo "Storing test data...\n";
foreach ($testData as $key => $value) {
    $fullKey = "serialization_test:$key";
    $result = $client->put($fullKey, $value);
    echo "  $key: " . ($result ? "✓" : "✗") . "\n";
}

echo "\nRetrieving and verifying test data...\n";
$allPassed = true;
foreach ($testData as $key => $originalValue) {
    $fullKey = "serialization_test:$key";
    $retrievedValue = $client->get($fullKey);
    
    // Use == for objects since === requires same instance
    $passed = is_object($originalValue) ? ($retrievedValue == $originalValue) : ($retrievedValue === $originalValue);
    $allPassed = $allPassed && $passed;
    
    echo "  $key: " . ($passed ? "✓" : "✗");
    if (!$passed) {
        echo " (Expected: " . var_export($originalValue, true) . 
             ", Got: " . var_export($retrievedValue, true) . ")";
    }
    echo "\n";
}

// Test bulk operations
echo "\nTesting bulk operations...\n";
$bulkKeys = array_map(fn($key) => "serialization_test:$key", array_keys($testData));
$bulkResult = $client->bulkGet($bulkKeys);

$bulkPassed = true;
foreach ($testData as $key => $originalValue) {
    $fullKey = "serialization_test:$key";
    $retrievedValue = $bulkResult[$fullKey] ?? null;
    
    // Use == for objects since === requires same instance
    $passed = is_object($originalValue) ? ($retrievedValue == $originalValue) : ($retrievedValue === $originalValue);
    $bulkPassed = $bulkPassed && $passed;
    
    echo "  bulk $key: " . ($passed ? "✓" : "✗");
    if (!$passed) {
        echo " (Expected: " . var_export($originalValue, true) . 
             ", Got: " . var_export($retrievedValue, true) . ")";
    }
    echo "\n";
}

// Cleanup
echo "\nCleaning up test data...\n";
foreach (array_keys($testData) as $key) {
    $fullKey = "serialization_test:$key";
    $client->delete($fullKey);
}

echo "\nResults:\n";
echo "========\n";
echo "Individual operations: " . ($allPassed ? "✓ PASSED" : "✗ FAILED") . "\n";
echo "Bulk operations: " . ($bulkPassed ? "✓ PASSED" : "✗ FAILED") . "\n";
echo "Overall: " . ($allPassed && $bulkPassed ? "✓ ALL TESTS PASSED" : "✗ SOME TESTS FAILED") . "\n";
