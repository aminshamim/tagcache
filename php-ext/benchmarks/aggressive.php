<?php
/**
 * Aggressive Performance Benchmark for TagCache PHP Extension
 * Tests ultra-fast GET/PUT, connection pinning, and batched bulk operations
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

// Connect to TagCache server
$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 16,  // Larger pool for connection pinning
    'connect_timeout_ms' => 100,
    'read_timeout_ms' => 100
]);

if (!$client) {
    die("Failed to connect to TagCache server\n");
}

echo "=== TagCache PHP Extension Aggressive Performance Test ===\n\n";

function benchmark($name, $iterations, $callable) {
    echo "Running: $name ($iterations iterations)\n";
    
    $start = microtime(true);
    $result = $callable($iterations);
    $end = microtime(true);
    
    $duration = $end - $start;
    $ops_per_sec = $iterations / $duration;
    
    printf("  Time: %.3fs | Ops/sec: %s | Result: %s\n", 
           $duration, 
           number_format($ops_per_sec, 0), 
           $result ? "✓" : "✗"
    );
    printf("  Latency: %.3fμs per op\n\n", 
           ($duration * 1000000) / $iterations
    );
    
    return $ops_per_sec;
}

// Test 1: Ultra-fast single GET/PUT operations
echo "1. Single Operation Tests\n";
echo "========================\n";

$get_ops = benchmark("Fast GET operations", 50000, function($n) use ($client) {
    // Pre-populate
    tagcache_put($client, "test_key", "test_value", [], 3600);
    
    $success = 0;
    for ($i = 0; $i < $n; $i++) {
        $result = tagcache_get($client, "test_key");
        if ($result !== null) $success++;
    }
    return $success == $n;
});

$put_ops = benchmark("Fast PUT operations", 25000, function($n) use ($client) {
    $success = 0;
    for ($i = 0; $i < $n; $i++) {
        $result = tagcache_put($client, "perf_key_$i", "value_$i", [], 3600);
        if ($result) $success++;
    }
    return $success == $n;
});

// Test 2: Connection pinning effectiveness
echo "2. Connection Pinning Test\n";
echo "==========================\n";

$pinned_ops = benchmark("Sequential ops (connection pinning)", 30000, function($n) use ($client) {
    $success = 0;
    // Do sequential GET/PUT on same connection to test pinning
    for ($i = 0; $i < $n; $i++) {
        $key = "pinned_$i";
        if (tagcache_put($client, $key, "data_$i", [], 3600)) {
            $val = tagcache_get($client, $key);
            if ($val === "data_$i") $success++;
        }
    }
    return $success == $n;
});

// Test 3: Batched bulk operations
echo "3. Bulk Operation Tests\n";
echo "=======================\n";

// Small batches (optimal for latency)
$small_bulk = benchmark("Small bulk PUT (100 items/batch)", 500, function($n) use ($client) {
    $success = 0;
    for ($batch = 0; $batch < $n; $batch++) {
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items["bulk_small_{$batch}_{$i}"] = "data_{$batch}_{$i}";
        }
        $result = tagcache_bulk_put($client, $items, 3600);
        if ($result == 100) $success++;
    }
    return $success == $n;
});

// Large batches (optimal for throughput)
$large_bulk = benchmark("Large bulk PUT (1000 items/batch)", 100, function($n) use ($client) {
    $success = 0;
    for ($batch = 0; $batch < $n; $batch++) {
        $items = [];
        for ($i = 0; $i < 1000; $i++) {
            $items["bulk_large_{$batch}_{$i}"] = "data_{$batch}_{$i}";
        }
        $result = tagcache_bulk_put($client, $items, 3600);
        if ($result == 1000) $success++;
    }
    return $success == $n;
});

// Test 4: Mixed workload simulation
echo "4. Mixed Workload Simulation\n";
echo "============================\n";

$mixed_ops = benchmark("Mixed GET/PUT/BULK workload", 10000, function($n) use ($client) {
    $success = 0;
    for ($i = 0; $i < $n; $i++) {
        $op = $i % 10;
        
        if ($op < 6) {
            // 60% GETs
            $result = tagcache_get($client, "test_key");
            if ($result !== null) $success++;
        } elseif ($op < 9) {
            // 30% PUTs  
            $result = tagcache_put($client, "mixed_$i", "value_$i", [], 3600);
            if ($result) $success++;
        } else {
            // 10% Bulk operations
            $items = [];
            for ($j = 0; $j < 10; $j++) {
                $items["bulk_mixed_{$i}_{$j}"] = "data_{$i}_{$j}";
            }
            $result = tagcache_bulk_put($client, $items, 3600);
            if ($result == 10) $success++;
        }
    }
    return $success == $n;
});

// Test 5: Stress test with maximum concurrency
echo "5. Maximum Throughput Stress Test\n";
echo "=================================\n";

$stress_ops = benchmark("Maximum throughput stress", 100000, function($n) use ($client) {
    $success = 0;
    $chunk_size = 1000;
    
    for ($chunk = 0; $chunk < $n / $chunk_size; $chunk++) {
        // Bulk insert
        $items = [];
        for ($i = 0; $i < $chunk_size; $i++) {
            $items["stress_{$chunk}_{$i}"] = str_repeat('x', 100); // 100-byte values
        }
        $bulk_result = tagcache_bulk_put($client, $items, 3600);
        
        // Random reads
        for ($i = 0; $i < 100; $i++) {
            $key = "stress_{$chunk}_" . rand(0, $chunk_size - 1);
            $result = tagcache_get($client, $key);
            if ($result !== null) $success++;
        }
        
        $success += $bulk_result;
    }
    return $success > ($n * 0.9); // 90% success rate acceptable under stress
});

// Summary
echo "=== Performance Summary ===\n";
printf("Single GET:           %s ops/sec\n", number_format($get_ops, 0));
printf("Single PUT:           %s ops/sec\n", number_format($put_ops, 0));
printf("Pinned Operations:    %s ops/sec\n", number_format($pinned_ops, 0));
printf("Small Bulk (100x):   %s items/sec\n", number_format($small_bulk * 100, 0));
printf("Large Bulk (1000x):  %s items/sec\n", number_format($large_bulk * 1000, 0));
printf("Mixed Workload:       %s ops/sec\n", number_format($mixed_ops, 0));
printf("Stress Test:          %s ops/sec\n", number_format($stress_ops, 0));

echo "\n=== Optimization Status ===\n";
$max_single_ops = max($get_ops, $put_ops, $pinned_ops);
$max_bulk_ops = max($small_bulk * 100, $large_bulk * 1000);
$overall_max = max($max_single_ops, $max_bulk_ops, $mixed_ops, $stress_ops);

printf("Peak throughput: %s ops/sec\n", number_format($overall_max, 0));
printf("Target (500k):   500,000 ops/sec\n");
printf("Achievement:     %.1f%% of target\n", ($overall_max / 500000) * 100);

if ($overall_max >= 250000) {
    echo "✓ EXCELLENT: Over 250k ops/sec achieved!\n";
} elseif ($overall_max >= 100000) {
    echo "✓ GOOD: Over 100k ops/sec achieved\n";
} elseif ($overall_max >= 50000) {
    echo "~ OK: Over 50k ops/sec achieved\n";
} else {
    echo "✗ NEEDS WORK: Under 50k ops/sec\n";
}

tagcache_close($client);
echo "\nBenchmark complete.\n";
?>