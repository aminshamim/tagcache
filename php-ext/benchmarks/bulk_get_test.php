<?php
/**
 * Bulk GET Operation Test and Benchmark
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 8,
    'connect_timeout_ms' => 100,
    'read_timeout_ms' => 100
]);

if (!$client) {
    die("❌ Failed to connect\n");
}

echo "🔍 BULK GET OPERATION TEST\n";
echo str_repeat("=", 50) . "\n";

// 1. Create test data
echo "\n1. Creating test data...\n";
$test_data = [];
$key_count = 1000;

for ($i = 0; $i < $key_count; $i++) {
    $key = "bulk_test_$i";
    $value = "test_value_$i";
    $test_data[$key] = $value;
    
    if (!tagcache_put($client, $key, $value, [], 3600)) {
        echo "❌ Failed to create key: $key\n";
        break;
    }
}

echo "✅ Created $key_count test keys\n";

// 2. Test bulk GET functionality
echo "\n2. Testing bulk GET functionality...\n";

// Test with small batch
$test_keys = array_slice(array_keys($test_data), 0, 10);
echo "Testing with " . count($test_keys) . " keys: " . implode(', ', $test_keys) . "\n";

$bulk_results = tagcache_bulk_get($client, $test_keys);

echo "Bulk GET results:\n";
foreach ($test_keys as $key) {
    $expected = $test_data[$key];
    $actual = $bulk_results[$key] ?? null;
    $status = ($actual === $expected) ? "✅" : "❌";
    echo "  $key: $status (expected: '$expected', got: '" . ($actual ?? 'NULL') . "')\n";
}

// 3. Performance comparison: Single GET vs Bulk GET
echo "\n3. Performance Comparison...\n";

function benchmark_operation($name, $operation) {
    $start = hrtime(true);
    $result = $operation();
    $end = hrtime(true);
    
    $duration = ($end - $start) / 1e9;
    $count = $result['count'] ?? 0;
    $success = $result['success'] ?? false;
    $ops_per_sec = $duration > 0 ? $count / $duration : 0;
    
    printf("%-20s: %s | %6.3fs | %s ops/sec | %s\n", 
           $name, 
           $success ? "✅" : "❌",
           $duration,
           number_format($ops_per_sec, 0),
           $success ? "SUCCESS" : "FAILED"
    );
    
    return $ops_per_sec;
}

$batch_sizes = [10, 50, 100, 500];

foreach ($batch_sizes as $batch_size) {
    echo "\n--- Batch Size: $batch_size ---\n";
    
    $test_keys = array_slice(array_keys($test_data), 0, $batch_size);
    
    // Single GET operations
    $single_perf = benchmark_operation("Single GET x$batch_size", function() use ($client, $test_keys) {
        $success_count = 0;
        foreach ($test_keys as $key) {
            $result = tagcache_get($client, $key);
            if ($result !== null) $success_count++;
        }
        return ['count' => count($test_keys), 'success' => $success_count === count($test_keys)];
    });
    
    // Bulk GET operation
    $bulk_perf = benchmark_operation("Bulk GET x$batch_size", function() use ($client, $test_keys) {
        $results = tagcache_bulk_get($client, $test_keys);
        $success_count = 0;
        foreach ($test_keys as $key) {
            if (isset($results[$key])) $success_count++;
        }
        return ['count' => count($test_keys), 'success' => $success_count === count($test_keys)];
    });
    
    // Calculate speedup
    $speedup = $single_perf > 0 ? $bulk_perf / $single_perf : 0;
    printf("Bulk GET Speedup: %.1fx faster\n", $speedup);
}

// 4. Large scale bulk GET test
echo "\n4. Large Scale Bulk GET Test...\n";

$large_batch_sizes = [1000, 2000, 5000];

foreach ($large_batch_sizes as $batch_size) {
    if ($batch_size > $key_count) continue;
    
    echo "\nTesting bulk GET with $batch_size keys...\n";
    $test_keys = array_slice(array_keys($test_data), 0, $batch_size);
    
    $bulk_perf = benchmark_operation("Large Bulk GET", function() use ($client, $test_keys) {
        $results = tagcache_bulk_get($client, $test_keys);
        return ['count' => count($test_keys), 'success' => count($results) > 0];
    });
    
    echo "Throughput: " . number_format($bulk_perf, 0) . " keys/sec\n";
}

// 5. API Usage Example
echo "\n5. API Usage Example...\n";
echo "```php\n";
echo "// Create TagCache client\n";
echo "\$client = tagcache_create(['mode' => 'tcp', 'host' => '127.0.0.1', 'port' => 1984]);\n\n";
echo "// Bulk GET multiple keys at once\n";
echo "\$keys = ['key1', 'key2', 'key3', 'key4'];\n";
echo "\$results = tagcache_bulk_get(\$client, \$keys);\n\n";
echo "// Results is an associative array:\n";
echo "// \$results = ['key1' => 'value1', 'key2' => 'value2', ...];\n";
echo "// Missing keys are simply not included in the result\n";
echo "```\n";

echo "\n✅ Bulk GET test complete!\n";

tagcache_close($client);
?>