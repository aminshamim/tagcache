<?php
/**
 * Aggressive stress test with very high operation counts
 * Tests single ops, bulk ops, and mixed workloads at extreme scales
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

function format_ops($ops, $time) {
    return number_format($ops / $time, 0) . " ops/sec";
}

function run_stress_test($name, $callback, $iterations = 1000000) {
    echo "\n=== $name (n=$iterations) ===\n";
    
    $start = microtime(true);
    $result = $callback($iterations);
    $end = microtime(true);
    
    $duration = $end - $start;
    $ops_per_sec = $iterations / $duration;
    
    printf("Duration: %.3fs\n", $duration);
    printf("Throughput: %s\n", format_ops($iterations, $duration));
    printf("Avg latency: %.1fμs per op\n", ($duration * 1000000) / $iterations);
    
    if ($result !== true) {
        echo "❌ Test failed: $result\n";
    } else {
        echo "✅ Test passed\n";
    }
    
    return $ops_per_sec;
}

echo "🚀 TagCache PHP Extension - Aggressive Stress Test\n";
echo "Testing with VERY HIGH operation counts...\n";

// Connect to server
$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 16, // More connections for stress test
    'connect_timeout_ms' => 100,
    'read_timeout_ms' => 100
]);

if (!$client) {
    die("❌ Failed to connect to TagCache server\n");
}

echo "✅ Connected to TagCache server\n";

// Test 1: Single PUT operations - 1M ops
$put_perf = run_stress_test("Single PUT Operations", function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        $key = "stress_put_$i";
        $value = "value_$i";
        if (!tagcache_put($client, $key, $value, [], 3600)) {
            return "PUT failed at iteration $i";
        }
    }
    return true;
}, 1000000);

// Test 2: Single GET operations - 1M ops  
$get_perf = run_stress_test("Single GET Operations", function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        $key = "stress_put_$i";
        $result = tagcache_get($client, $key);
        if ($result === null) {
            return "GET failed at iteration $i";
        }
    }
    return true;
}, 1000000);

// Test 3: Mixed GET/PUT - 2M ops total
$mixed_perf = run_stress_test("Mixed GET/PUT Operations", function($n) use ($client) {
    for ($i = 0; $i < $n/2; $i++) {
        // PUT
        $key = "mixed_$i";
        $value = "value_$i";
        if (!tagcache_put($client, $key, $value, [], 3600)) {
            return "PUT failed at iteration $i";
        }
        
        // GET
        $result = tagcache_get($client, $key);
        if ($result === null) {
            return "GET failed at iteration $i";
        }
    }
    return true;
}, 2000000);

// Test 4: Bulk operations - 100K items per bulk
$bulk_perf = run_stress_test("Bulk PUT Operations", function($n) use ($client) {
    $batch_size = 100000;
    $batches = (int)($n / $batch_size);
    
    for ($batch = 0; $batch < $batches; $batch++) {
        $items = [];
        for ($i = 0; $i < $batch_size; $i++) {
            $key = "bulk_" . ($batch * $batch_size + $i);
            $items[$key] = "bulk_value_$i";
        }
        
        if (!tagcache_bulk_put($client, $items, 3600)) {
            return "Bulk PUT failed at batch $batch";
        }
    }
    return true;
}, 1000000);

// Test 5: Large value operations - 1KB values
$large_perf = run_stress_test("Large Value Operations (1KB)", function($n) use ($client) {
    $large_value = str_repeat("x", 1024); // 1KB value
    
    for ($i = 0; $i < $n; $i++) {
        $key = "large_$i";
        if (!tagcache_put($client, $key, $large_value, [], 3600)) {
            return "Large PUT failed at iteration $i";
        }
        
        $result = tagcache_get($client, $key);
        if ($result === null || strlen($result) !== 1024) {
            return "Large GET failed at iteration $i";
        }
    }
    return true;
}, 100000);

// Test 6: High connection stress - rapid connect/disconnect simulation
$conn_perf = run_stress_test("Connection Stress Test", function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        // Force new operations to test connection handling
        $key = "conn_test_$i";
        $value = "conn_value_$i";
        
        if (!tagcache_put($client, $key, $value, [], 3600)) {
            return "Connection PUT failed at iteration $i";
        }
        
        if (tagcache_get($client, $key) === null) {
            return "Connection GET failed at iteration $i";
        }
        
        // Occasional delete to vary workload
        if ($i % 100 === 0) {
            tagcache_delete($client, $key);
        }
    }
    return true;
}, 500000);

echo "\n🎯 PERFORMANCE SUMMARY\n";
echo "====================\n";
printf("Single PUT:     %s\n", format_ops(1000000, 1000000 / $put_perf));
printf("Single GET:     %s\n", format_ops(1000000, 1000000 / $get_perf));
printf("Mixed Ops:      %s\n", format_ops(2000000, 2000000 / $mixed_perf));
printf("Bulk PUT:       %s\n", format_ops(1000000, 1000000 / $bulk_perf));
printf("Large Values:   %s\n", format_ops(100000, 100000 / $large_perf));
printf("Conn Stress:    %s\n", format_ops(500000, 500000 / $conn_perf));

echo "\n🎯 TARGET ANALYSIS\n";
echo "==================\n";
echo "Target: 400k+ ops/sec for single operations\n";
echo "Target: 500k+ ops/sec for bulk operations\n";

if ($put_perf >= 400000) {
    echo "✅ Single PUT: MEETS TARGET\n";
} else {
    echo "❌ Single PUT: BELOW TARGET (" . number_format(400000 - $put_perf, 0) . " ops/sec gap)\n";
}

if ($get_perf >= 400000) {
    echo "✅ Single GET: MEETS TARGET\n";
} else {
    echo "❌ Single GET: BELOW TARGET (" . number_format(400000 - $get_perf, 0) . " ops/sec gap)\n";
}

if ($bulk_perf >= 500000) {
    echo "✅ Bulk PUT: MEETS TARGET\n";
} else {
    echo "❌ Bulk PUT: BELOW TARGET (" . number_format(500000 - $bulk_perf, 0) . " ops/sec gap)\n";
}

echo "\n🔬 Next optimization priorities:\n";
if ($put_perf < 400000 || $get_perf < 400000) {
    echo "1. Aggressive connection pooling\n";
    echo "2. Ultra-fast serialization\n";
    echo "3. Command pipelining\n";
}

echo "\nStress test complete!\n";

// Close connection
tagcache_close($client);
?>