<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;
use TagCache\Transport\HttpTransport;
use TagCache\Exceptions\ConnectionException;

echo "=== TagCache PHP SDK Demo ===\n";

// Configuration
$config = new Config([
    'mode' => 'http',
    'http' => [
        'base_url' => 'http://localhost:3030',
        'timeout_ms' => 5000,
        'retries' => 3,
    ]
]);

$client = new Client($config);

try {
    // Test server connectivity
    echo "1. Testing server connectivity...\n";
    $health = $client->health();
    echo "✓ Server is healthy: " . json_encode($health) . "\n";
    
    // Login to get authentication token
    echo "\n2. Authenticating with server...\n";
    $token = $client->login('umF6zQOspeAWvyZF', 'hmH4KJP1PT9oQIGBpkpLdrgu');
    echo "✓ Login successful, got token: " . substr($token, 0, 20) . "...\n";
    
    // Update config with token
    $configWithAuth = new Config([
        'mode' => 'http',
        'http' => [
            'base_url' => 'http://localhost:3030',
            'timeout_ms' => 5000,
            'retries' => 3,
        ],
        'auth' => [
            'token' => $token,
        ]
    ]);
    $client = new Client($configWithAuth);
    
    // Test basic put/get
    echo "\n3. Testing basic put/get operations...\n";
    $testKey = 'demo:test:' . uniqid();
    $testValue = 'Hello TagCache! Time: ' . date('Y-m-d H:i:s');
    $tags = ['demo', 'test', 'php-sdk'];
    
    $success = $client->put($testKey, $testValue, $tags, 300);
    echo "✓ Put operation: " . ($success ? 'SUCCESS' : 'FAILED') . "\n";
    
    $retrievedItem = $client->get($testKey);
    $retrievedValue = $retrievedItem ? $retrievedItem->value : null;
    echo "✓ Get operation: " . ($retrievedValue === $testValue ? 'SUCCESS' : 'FAILED') . "\n";
    echo "  Retrieved: '$retrievedValue'\n";
    
    // Test tag operations
    echo "\n4. Testing tag operations...\n";
    $keys = $client->keysByTag('demo');
    echo "✓ Keys with 'demo' tag: " . count($keys) . " keys found\n";
    $keyStrings = array_map(fn($item) => $item instanceof \TagCache\Models\Item ? $item->key : (string)$item, $keys);
    echo "  Keys: " . implode(', ', array_slice($keyStrings, 0, 3)) . (count($keys) > 3 ? '...' : '') . "\n";
    
    // Test bulk operations
    echo "\n4. Testing bulk operations...\n";
    $bulkKeys = [];
    for ($i = 1; $i <= 5; $i++) {
        $key = "bulk:demo:$i:" . uniqid();
        $bulkKeys[] = $key;
        $client->put($key, "Bulk value #$i", ['bulk', 'demo'], 300);
    }
    
    $bulkResults = $client->bulkGet($bulkKeys);
    echo "✓ Bulk get: " . count($bulkResults) . " out of " . count($bulkKeys) . " keys retrieved\n";
    
    // Test search
    echo "\n5. Testing search functionality...\n";
    $searchResults = $client->search(['pattern' => 'demo:*']);
    echo "✓ Search results: " . count($searchResults) . " keys found\n";
    
    // Test stats
    echo "\n6. Getting cache statistics...\n";
    $stats = $client->stats();
    echo "✓ Total keys: " . ($stats['total_keys'] ?? 'N/A') . "\n";
    echo "✓ Memory usage: " . ($stats['total_memory_usage'] ?? 'N/A') . " bytes\n";
    
    // Test tag invalidation
    echo "\n7. Testing tag invalidation...\n";
    $beforeCount = count($client->keysByTag('demo'));
    $client->invalidateTags(['demo']);
    $afterCount = count($client->keysByTag('demo'));
    echo "✓ Tag invalidation: $beforeCount → $afterCount keys (removed " . ($beforeCount - $afterCount) . ")\n";
    
    echo "\n=== All tests completed successfully! ===\n";
    
} catch (ConnectionException $e) {
    echo "❌ Connection error: " . $e->getMessage() . "\n";
    echo "   Make sure the TagCache server is running on localhost:3030\n";
    echo "   Start with: PORT=3030 TCP_PORT=3031 cargo run --release --bin tagcache\n";
} catch (\Exception $e) {
    echo "❌ Error: " . get_class($e) . " - " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
}
