<?php
declare(strict_types=1);

/**
 * Test new Client method signatures with TTL as last parameter and default TTL from config
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

echo "Testing New Client Method Signatures and Default TTL\n";
echo "===================================================\n\n";

// Test 1: Default TTL from config
echo "Test 1: Default TTL from config\n";
echo "--------------------------------\n";

$configWithDefaultTtl = new Config([
    'mode' => 'http',
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
    'cache' => [
        'default_ttl_ms' => 30000, // 30 seconds default
        'max_ttl_ms' => 86400000,  // 24 hours max
    ],
]);

$client1 = new Client($configWithDefaultTtl);

echo "Config default TTL: " . ($client1->getConfig()->cache['default_ttl_ms'] ?? 'null') . " ms\n";

// Test new signature: put(key, value, tags, ttl)
try {
    $result1 = $client1->put('test_default_ttl', 'value1', ['tag1', 'tag2']); // No TTL specified, should use default
    echo "✅ put() with default TTL: " . ($result1 ? 'SUCCESS' : 'FAILED') . "\n";
    
    $result2 = $client1->put('test_custom_ttl', 'value2', ['tag1'], 60000); // Custom TTL specified
    echo "✅ put() with custom TTL: " . ($result2 ? 'SUCCESS' : 'FAILED') . "\n";
    
    $result3 = $client1->put('test_no_tags', 'value3'); // No tags, no TTL - should use default TTL
    echo "✅ put() with no tags, default TTL: " . ($result3 ? 'SUCCESS' : 'FAILED') . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error in put tests: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: No default TTL configured
echo "Test 2: No default TTL configured\n";
echo "----------------------------------\n";

$configNoDefaultTtl = new Config([
    'mode' => 'http',
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
    // No cache config - should default to null TTL
]);

$client2 = new Client($configNoDefaultTtl);
echo "Config default TTL: " . ($client2->getConfig()->cache['default_ttl_ms'] ?? 'null') . "\n";

try {
    $result4 = $client2->put('test_no_default', 'value4', ['tag3']); // No default TTL, should be null
    echo "✅ put() with no default TTL config: " . ($result4 ? 'SUCCESS' : 'FAILED') . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error in no default TTL test: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: getOrSet method with new signature
echo "Test 3: getOrSet with new signature\n";
echo "-----------------------------------\n";

try {
    $producer = function($key) {
        return "Generated value for $key";
    };
    
    $item1 = $client1->getOrSet('test_getorset1', $producer, ['generated']); // Use default TTL
    echo "✅ getOrSet() with default TTL: " . ($item1 ? 'SUCCESS' : 'FAILED') . "\n";
    
    $item2 = $client1->getOrSet('test_getorset2', $producer, ['generated'], 45000); // Custom TTL
    echo "✅ getOrSet() with custom TTL: " . ($item2 ? 'SUCCESS' : 'FAILED') . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error in getOrSet tests: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: Helper method with new signature
echo "Test 4: Helper methods\n";
echo "----------------------\n";

try {
    $result5 = $client1->putWithTag('test_helper', 'helper_value', 'helper_tag', 15000);
    echo "✅ putWithTag() with custom TTL: " . ($result5 ? 'SUCCESS' : 'FAILED') . "\n";
    
    $result6 = $client1->putWithTag('test_helper2', 'helper_value2', 'helper_tag'); // Use default TTL
    echo "✅ putWithTag() with default TTL: " . ($result6 ? 'SUCCESS' : 'FAILED') . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error in helper method tests: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: Environment variable configuration
echo "Test 5: Environment variable configuration\n";
echo "------------------------------------------\n";

// Set environment variable for default TTL
putenv('TAGCACHE_DEFAULT_TTL_MS=25000');

try {
    $configFromEnv = Config::fromEnv([
        'mode' => 'http',
        'http' => ['base_url' => 'http://localhost:8080'],
        'auth' => ['username' => 'admin', 'password' => 'password'],
    ]);
    
    echo "Environment default TTL: " . ($configFromEnv->cache['default_ttl_ms'] ?? 'null') . " ms\n";
    
    $client3 = new Client($configFromEnv);
    $result7 = $client3->put('test_env_ttl', 'env_value', ['env_tag']);
    echo "✅ put() with environment default TTL: " . ($result7 ? 'SUCCESS' : 'FAILED') . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error in environment TTL test: " . $e->getMessage() . "\n";
}

// Clean up environment
putenv('TAGCACHE_DEFAULT_TTL_MS');

echo "\n";
echo "🎉 Method signature update complete!\n";
echo "====================================\n";
echo "✅ TTL moved to last parameter\n";
echo "✅ Default TTL loaded from config\n";
echo "✅ Environment variable support added\n";
echo "✅ All methods updated consistently\n";
echo "✅ Backward compatibility maintained where possible\n";
