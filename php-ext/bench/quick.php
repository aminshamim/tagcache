<?php
/**
 * Quick Performance Test for TagCache PHP Extension
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

// Connect
$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 8,
    'connect_timeout_ms' => 100,
    'read_timeout_ms' => 100
]);

if (!$client) {
    die("Failed to connect to TagCache server\n");
}

echo "=== Quick TagCache Extension Performance Test ===\n\n";

function quick_bench($name, $iterations, $callable) {
    echo "Running: $name ($iterations ops)\n";
    
    $start = microtime(true);
    $result = $callable($iterations);
    $end = microtime(true);
    
    $duration = $end - $start;
    $ops_per_sec = $iterations / $duration;
    
    printf("  %.3fs | %s ops/sec | %s\n\n", 
           $duration, 
           number_format($ops_per_sec, 0), 
           $result ? "✓" : "✗"
    );
    
    return $ops_per_sec;
}

// Test 1: Single GET/PUT
echo "1. Single Operations\n";
echo "===================\n";

// Pre-populate for GET test
tagcache_put($client, "test_key", "test_value", [], 3600);

$get_ops = quick_bench("GET operations", 10000, function($n) use ($client) {
    $success = 0;
    for ($i = 0; $i < $n; $i++) {
        $result = tagcache_get($client, "test_key");
        if ($result !== null) $success++;
    }
    return $success == $n;
});

$put_ops = quick_bench("PUT operations", 5000, function($n) use ($client) {
    $success = 0;
    for ($i = 0; $i < $n; $i++) {
        $result = tagcache_put($client, "quick_$i", "value_$i", [], 3600);
        if ($result) $success++;
    }
    return $success == $n;
});

// Test 2: Bulk operations
echo "2. Bulk Operations\n";
echo "==================\n";

$bulk_ops = quick_bench("Bulk PUT (100 items/batch)", 50, function($n) use ($client) {
    $success = 0;
    for ($batch = 0; $batch < $n; $batch++) {
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items["bulk_{$batch}_{$i}"] = "data_{$batch}_{$i}";
        }
        $result = tagcache_bulk_put($client, $items, 3600);
        if ($result == 100) $success++;
    }
    return $success == $n;
});

// Test 3: Mixed workload
echo "3. Mixed Workload\n";
echo "=================\n";

$mixed_ops = quick_bench("Mixed GET/PUT", 2000, function($n) use ($client) {
    $success = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i % 2 == 0) {
            // GET
            $result = tagcache_get($client, "test_key");
            if ($result !== null) $success++;
        } else {
            // PUT
            $result = tagcache_put($client, "mixed_$i", "value_$i", [], 3600);
            if ($result) $success++;
        }
    }
    return $success == $n;
});

// Summary
echo "=== Summary ===\n";
printf("Single GET:    %s ops/sec\n", number_format($get_ops, 0));
printf("Single PUT:    %s ops/sec\n", number_format($put_ops, 0));
printf("Bulk (100x):   %s items/sec\n", number_format($bulk_ops * 100, 0));
printf("Mixed:         %s ops/sec\n", number_format($mixed_ops, 0));

$peak = max($get_ops, $put_ops, $bulk_ops * 100, $mixed_ops);
printf("\nPeak throughput: %s ops/sec\n", number_format($peak, 0));
printf("vs Server 500k:  %.1f%%\n", ($peak / 500000) * 100);

if ($peak >= 100000) {
    echo "✓ EXCELLENT: Over 100k ops/sec!\n";
} elseif ($peak >= 50000) {
    echo "✓ GOOD: Over 50k ops/sec\n";
} else {
    echo "~ Needs improvement\n";
}

tagcache_close($client);
?>