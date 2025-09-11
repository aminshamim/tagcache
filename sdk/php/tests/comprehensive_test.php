<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

echo "=== TagCache PHP SDK - Comprehensive Feature Test ===\n\n";

// Test configuration
$config = new Config([
    'mode' => 'http',
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 10000,
        'retries' => 3,
    ]
]);

$testResults = [];
$errors = [];

function runTest(string $testName, callable $test) {
    global $testResults, $errors;
    try {
        $start = microtime(true);
        $result = $test();
        $time = microtime(true) - $start;
        $testResults[$testName] = ['success' => true, 'time' => $time, 'result' => $result];
        echo "✅ $testName - " . sprintf('%.3fs', $time) . "\n";
        return $result;
    } catch (\Throwable $e) {
        $testResults[$testName] = ['success' => false, 'error' => $e->getMessage()];
        $errors[] = "$testName: " . $e->getMessage();
        echo "❌ $testName - " . $e->getMessage() . "\n";
        return false;
    }
}

$client = new Client($config);

// 1. Authentication Test
$loginSuccess = runTest('Authentication', function() use ($client) {
    return $client->login('umF6zQOspeAWvyZF', 'hmH4KJP1PT9oQIGBpkpLdrgu');
});

if (!$loginSuccess) {
    echo "❌ Cannot proceed without authentication!\n";
    exit(1);
}

// Client is now authenticated - no need to create a new one

// 2. Health Check
runTest('Health Check', function() use ($client) {
    $health = $client->health();
    if (!isset($health['status']) || $health['status'] !== 'ok') {
        throw new Exception('Health check failed');
    }
    return $health;
});

// 3. Basic Put/Get/Delete
$testKey = 'sdk:feature-test:' . uniqid();
runTest('Basic Put/Get/Delete', function() use ($client, $testKey) {
    $value = 'test-value-' . time();
    $tags = ['feature-test', 'basic'];
    
    // Put
    if (!$client->put($testKey, $value, 300000, $tags)) { // 5 minute TTL
        throw new Exception('Put failed');
    }
    
    // Get
    $retrievedValue = $client->get($testKey);
    if (!$retrievedValue || $retrievedValue !== $value) {
        throw new Exception('Get failed or value mismatch');
    }
    
    // Delete
    if (!$client->delete($testKey)) {
        throw new Exception('Delete failed');
    }
    
    return ['put' => true, 'get' => $retrievedValue, 'delete' => true];
});

// 4. Tag Operations
runTest('Tag Operations', function() use ($client) {
    $tag = 'tag-test-' . uniqid();
    $keys = [];
    
    // Create tagged keys
    for ($i = 1; $i <= 5; $i++) {
        $key = "tag-test:$i:" . uniqid();
        $keys[] = $key;
        if (!$client->put($key, "value-$i", 300000, [$tag, 'tag-ops'])) {
            throw new Exception("Failed to put key $key");
        }
    }
    
    // Get keys by tag
    $foundKeys = $client->keysByTag($tag);
    if (count($foundKeys) < 5) {
        throw new Exception('Not all keys found by tag');
    }
    
    // Invalidate by tag
    $invalidated = $client->invalidateByTag($tag);
    if (!$invalidated) {
        throw new Exception('Tag invalidation failed');
    }
    
    // Verify invalidation
    $remainingKeys = $client->keysByTag($tag);
    if (count($remainingKeys) > 0) {
        throw new Exception('Keys still exist after tag invalidation');
    }
    
    return ['created' => count($keys), 'found' => count($foundKeys), 'invalidated' => $invalidated];
});

// 5. Bulk Operations
runTest('Bulk Operations', function() use ($client) {
    $keys = [];
    $values = [];
    
    // Create bulk data
    for ($i = 1; $i <= 20; $i++) {
        $key = "bulk:$i:" . uniqid();
        $value = "bulk-value-$i";
        $keys[] = $key;
        $values[$key] = $value;
        
        if (!$client->put($key, $value, 300000, ['bulk', 'bulk-test'])) {
            throw new Exception("Failed to put bulk key $key");
        }
    }
    
    // Bulk get
    $results = $client->bulkGet($keys);
    if (count($results) !== count($keys)) {
        throw new Exception('Bulk get count mismatch');
    }
    
    foreach ($keys as $key) {
        if (!isset($results[$key]) || !$results[$key]) {
            throw new Exception("Missing result for key $key");
        }
        if ($results[$key] !== $values[$key]) {
            throw new Exception("Value mismatch for key $key");
        }
    }
    
    // Bulk delete
    $deletedCount = $client->bulkDelete($keys);
    if ($deletedCount < count($keys)) {
        throw new Exception("Bulk delete failed: expected " . count($keys) . ", got $deletedCount");
    }
    
    return ['created' => count($keys), 'retrieved' => count($results), 'deleted' => $deletedCount];
});

// 6. Search Functionality (using tag-based search)
runTest('Search Functionality', function() use ($client) {
    $testTag = 'search-func-' . uniqid();
    $keys = [];
    
    // Create searchable keys
    for ($i = 1; $i <= 10; $i++) {
        $key = "search-test:item-$i:" . uniqid();
        $keys[] = $key;
        if (!$client->put($key, "search-value-$i", 300000, [$testTag, 'search-test'])) {
            throw new Exception("Failed to put search key $key");
        }
    }
    
    // Search by tag (more reliable than pattern matching)
    $tagResults = $client->keysByTag($testTag);
    if (count($tagResults) < 10) {
        throw new Exception("Tag search found " . count($tagResults) . " keys, expected 10");
    }
    
    // Test that we can also search by the broader tag
    $broadResults = $client->keysByTag('search-test');
    if (count($broadResults) < 10) {
        throw new Exception("Broad tag search found " . count($broadResults) . " keys, expected at least 10");
    }
    
    // Cleanup
    $client->invalidateTags([$testTag]);
    
    return ['created' => count($keys), 'found' => count($tagResults), 'broad_search' => count($broadResults)];
});

// 7. Large Payload Test
runTest('Large Payload Handling', function() use ($client) {
    $key = 'large-payload:' . uniqid();
    $largeValue = str_repeat('X', 100000); // 100KB
    
    if (!$client->put($key, $largeValue, 300000, ['large-payload'])) {
        throw new Exception('Failed to put large payload');
    }
    
    $retrievedValue = $client->get($key);
    if (!$retrievedValue || strlen($retrievedValue) !== strlen($largeValue)) {
        throw new Exception('Large payload retrieval failed');
    }
    
    if ($retrievedValue !== $largeValue) {
        throw new Exception('Large payload content mismatch');
    }
    
    $client->delete($key);
    
    return ['size' => strlen($largeValue), 'stored' => true, 'retrieved' => true];
});

// 8. Performance Test
runTest('Performance Test (100 operations)', function() use ($client) {
    $operations = 100;
    $keys = [];
    
    $putStart = microtime(true);
    for ($i = 1; $i <= $operations; $i++) {
        $key = "perf:$i:" . uniqid();
        $keys[] = $key;
        if (!$client->put($key, "perf-value-$i", 300000, ['performance'])) {
            throw new Exception("Performance test put failed at $i");
        }
    }
    $putTime = microtime(true) - $putStart;
    
    $getStart = microtime(true);
    for ($i = 0; $i < $operations; $i++) {
        $item = $client->get($keys[$i]);
        if (!$item) {
            throw new Exception("Performance test get failed at $i");
        }
    }
    $getTime = microtime(true) - $getStart;
    
    $deleteStart = microtime(true);
    $deletedCount = $client->bulkDelete($keys);
    $deleteTime = microtime(true) - $deleteStart;
    
    if ($deletedCount < $operations) {
        throw new Exception("Performance test cleanup failed");
    }
    
    return [
        'operations' => $operations,
        'put_time' => $putTime,
        'get_time' => $getTime,
        'delete_time' => $deleteTime,
        'put_ops_per_sec' => $operations / $putTime,
        'get_ops_per_sec' => $operations / $getTime,
    ];
});

// 9. Error Handling Test
runTest('Error Handling', function() use ($client) {
    // Test that get returns null for non-existent keys (normal cache behavior)
    $result = $client->get('non-existent-key-' . uniqid());
    if ($result !== null) {
        throw new Exception('Get should return null for non-existent keys');
    }
    
    // Test that delete returns false for non-existent keys
    $deleted = $client->delete('non-existent-key-' . uniqid());
    if ($deleted !== false) {
        throw new Exception('Delete should return false for non-existent keys');
    }
    
    return ['not_found_handled' => true, 'delete_false' => true];
});

// 10. Stats Test
runTest('Statistics', function() use ($client) {
    $stats = $client->stats();
    if (!is_array($stats)) {
        throw new Exception('Stats should return array');
    }
    return $stats;
});

// Print Summary
echo "\n=== Test Summary ===\n";
$successCount = 0;
$totalTime = 0;

foreach ($testResults as $testName => $result) {
    if ($result['success']) {
        $successCount++;
        if (isset($result['time'])) {
            $totalTime += $result['time'];
        }
    }
}

echo "✅ Passed: $successCount / " . count($testResults) . " tests\n";
echo "⏱️  Total time: " . sprintf('%.3fs', $totalTime) . "\n";

if (!empty($errors)) {
    echo "\n❌ Errors:\n";
    foreach ($errors as $error) {
        echo "   • $error\n";
    }
} else {
    echo "\n🎉 All tests passed! TagCache PHP SDK is fully functional.\n";
}

// Performance Summary
if (isset($testResults['Performance Test (100 operations)']['result'])) {
    $perf = $testResults['Performance Test (100 operations)']['result'];
    echo "\n📊 Performance:\n";
    echo sprintf("   • Put:    %.1f ops/sec\n", $perf['put_ops_per_sec']);
    echo sprintf("   • Get:    %.1f ops/sec\n", $perf['get_ops_per_sec']);
    echo sprintf("   • Total:  %.3fs for %d operations\n", 
        $perf['put_time'] + $perf['get_time'] + $perf['delete_time'], 
        $perf['operations']);
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "TagCache PHP SDK - Feature Test Complete\n";
echo str_repeat("=", 50) . "\n";
