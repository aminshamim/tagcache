<?php

require_once 'AroggaCache_Fixed.php';

use App\Helper\AroggaCache;

echo "=== Testing Fixed AroggaCache Configuration ===\n\n";

// Test 1: Check if client creation works
echo "1. Testing client creation...\n";
try {
    $client = AroggaCache::getClient();
    echo "✅ Client created successfully\n";
} catch (Exception $e) {
    echo "❌ Client creation failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Health check
echo "\n2. Testing health check...\n";
if (AroggaCache::isHealthy()) {
    echo "✅ TagCache is healthy\n";
    $stats = AroggaCache::getStats();
    echo "   Stats: " . json_encode($stats) . "\n";
} else {
    echo "❌ TagCache is not healthy\n";
}

// Test 3: Basic operations
echo "\n3. Testing basic operations...\n";

// Set operation
$start = microtime(true);
AroggaCache::set('test_timeout_key', 'test_value', ['test', 'timeout']);
$set_time = (microtime(true) - $start) * 1000;
printf("✅ SET completed in %.2fms\n", $set_time);

// Get operation
$start = microtime(true);
$value = AroggaCache::get('test_timeout_key');
$get_time = (microtime(true) - $start) * 1000;
printf("✅ GET completed in %.2fms, value: %s\n", $get_time, $value);

// Test 4: Bulk operations
echo "\n4. Testing bulk operations...\n";
$keys = ['bulk1', 'bulk2', 'bulk3', 'nonexistent'];
AroggaCache::set('bulk1', 'value1', ['bulk']);
AroggaCache::set('bulk2', 'value2', ['bulk']);
AroggaCache::set('bulk3', 'value3', ['bulk']);

$start = microtime(true);
$results = AroggaCache::many($keys);
$bulk_time = (microtime(true) - $start) * 1000;
printf("✅ BULK GET completed in %.2fms\n", $bulk_time);
echo "   Results: " . json_encode($results) . "\n";

// Test 5: Tag invalidation
echo "\n5. Testing tag invalidation...\n";
$start = microtime(true);
$count = AroggaCache::clearTag('test');
$invalidate_time = (microtime(true) - $start) * 1000;
printf("✅ TAG INVALIDATION completed in %.2fms, %d items cleared\n", $invalidate_time, $count);

// Test 6: Performance test
echo "\n6. Performance test (100 operations)...\n";
$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    AroggaCache::set("perf_$i", "value_$i", ['performance']);
    $retrieved = AroggaCache::get("perf_$i");
}
$total_time = (microtime(true) - $start) * 1000;
$ops_per_sec = 200 / ($total_time / 1000); // 100 sets + 100 gets
printf("✅ Performance: 200 ops in %.2fms (%.0f ops/sec)\n", $total_time, $ops_per_sec);

// Cleanup
AroggaCache::clearTag('performance');
AroggaCache::clearTag('bulk');

echo "\n=== Test Results ===\n";
printf("SET operation: %.2fms\n", $set_time);
printf("GET operation: %.2fms\n", $get_time);
printf("BULK GET: %.2fms\n", $bulk_time);
printf("TAG INVALIDATION: %.2fms\n", $invalidate_time);
printf("Performance: %.0f ops/sec\n", $ops_per_sec);

if ($set_time < 100 && $get_time < 50 && $ops_per_sec > 1000) {
    echo "\n🎉 All tests passed! Configuration is working well.\n";
} else {
    echo "\n⚠️  Some operations are slower than expected. Check server load.\n";
}

?>