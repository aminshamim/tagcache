<?php
/**
 * Debug Mixed Workload Failures
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 8,
    'connect_timeout_ms' => 1000,
    'read_timeout_ms' => 1000
]);

if (!$client) {
    die("❌ Failed to connect\n");
}

echo "🔍 Debugging Mixed Workload Issues...\n\n";

// First, let's create some test data
echo "1. Creating test data...\n";
$test_keys = [];
for ($i = 0; $i < 10; $i++) {
    $key = "debug_final_put_$i";
    $value = "test_data_$i";
    
    $result = tagcache_put($client, $key, $value, [], 3600);
    if ($result) {
        $test_keys[] = $key;
        echo "   ✅ PUT $key: SUCCESS\n";
    } else {
        echo "   ❌ PUT $key: FAILED\n";
    }
}

echo "\n2. Testing GET operations on created keys...\n";
foreach ($test_keys as $key) {
    $result = tagcache_get($client, $key);
    if ($result !== null) {
        echo "   ✅ GET $key: SUCCESS (value: $result)\n";
    } else {
        echo "   ❌ GET $key: FAILED (NULL)\n";
    }
}

// Test the exact pattern from mixed workload
echo "\n3. Testing mixed workload pattern...\n";
$success_count = 0;
$total_tests = 100;

for ($i = 0; $i < $total_tests; $i++) {
    $op = $i % 3;
    $success = false;
    
    switch ($op) {
        case 0: // GET
            $key = "debug_final_put_" . ($i % 10); // Use existing keys
            $result = tagcache_get($client, $key);
            $success = ($result !== null);
            echo "   GET $key: " . ($success ? "✅" : "❌") . "\n";
            break;
            
        case 1: // PUT
            $key = "mixed_debug_$i";
            $value = "value_$i";
            $success = tagcache_put($client, $key, $value, [], 3600);
            echo "   PUT $key: " . ($success ? "✅" : "❌") . "\n";
            break;
            
        case 2: // DELETE
            if ($i >= 1000) { // Only delete if we have something to delete
                $key = "mixed_debug_" . ($i - 1000);
                $success = tagcache_delete($client, $key);
                echo "   DEL $key: " . ($success ? "✅" : "❌") . "\n";
            } else {
                $success = true; // Skip delete, count as success
                echo "   DEL (skipped): ✅\n";
            }
            break;
    }
    
    if ($success) $success_count++;
    
    if (!$success) {
        echo "❌ FIRST FAILURE at iteration $i, operation type $op\n";
        break;
    }
}

echo "\n4. Results: $success_count/$total_tests operations successful\n";

// Test precise timing measurement
echo "\n5. Testing timing precision...\n";
$start = microtime(true);
usleep(100000); // 100ms sleep
$end = microtime(true);
$measured = $end - $start;
echo "   Measured 100ms sleep: " . ($measured * 1000) . "ms\n";
echo "   Timing precision: " . ($measured > 0.05 ? "✅ GOOD" : "❌ BAD") . "\n";

// Test small operation timing
echo "\n6. Testing small operation timing...\n";
$iterations = 1000;
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    tagcache_get($client, "debug_final_put_0");
}
$end = microtime(true);
$duration = $end - $start;
$ops_per_sec = $duration > 0 ? $iterations / $duration : 0;

echo "   Duration for $iterations GETs: " . ($duration * 1000) . "ms\n";
echo "   Calculated throughput: " . number_format($ops_per_sec, 0) . " ops/sec\n";
echo "   Timing sanity: " . ($duration > 0.001 ? "✅ REASONABLE" : "❌ TOO FAST") . "\n";

tagcache_close($client);
echo "\n✅ Debug complete\n";
?>