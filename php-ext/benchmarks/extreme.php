<?php
/**
 * Extreme Performance Test - Maximum Stress
 * This test pushes the extension to absolute limits
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

// Connect with maximum pool size
$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 32, // Maximum connections
    'connect_timeout_ms' => 50,
    'read_timeout_ms' => 50
]);

if (!$client) {
    die("❌ Failed to connect to TagCache server\n");
}

function extreme_bench($name, $iterations, $callable) {
    echo "\n🔥 $name (n=" . number_format($iterations) . ")\n";
    echo str_repeat("=", 50) . "\n";
    
    // Memory and time tracking
    $mem_start = memory_get_usage();
    $start = microtime(true);
    
    $result = $callable($iterations);
    
    $end = microtime(true);
    $mem_end = memory_get_usage();
    
    $duration = $end - $start;
    $ops_per_sec = $iterations / $duration;
    $mem_used = $mem_end - $mem_start;
    
    printf("⏱️  Duration: %.3fs\n", $duration);
    printf("🚀 Throughput: %s ops/sec\n", number_format($ops_per_sec, 0));
    printf("⚡ Latency: %.1fμs per op\n", ($duration * 1000000) / $iterations);
    printf("💾 Memory: %s\n", $mem_used > 0 ? "+".number_format($mem_used/1024, 1)."KB" : "0KB");
    printf("📊 Status: %s\n", $result === true ? "✅ PASSED" : "❌ FAILED: $result");
    
    return $ops_per_sec;
}

echo "🔥 EXTREME PERFORMANCE TEST\n";
echo "Testing at MAXIMUM scale...\n";

// Pre-populate cache for GET tests
echo "\n📝 Pre-populating cache...\n";
for ($i = 0; $i < 100000; $i++) {
    tagcache_put($client, "preload_$i", "data_$i", [], 3600);
    if ($i % 10000 === 0) echo "  " . number_format($i) . " keys loaded...\n";
}
echo "✅ 100k keys pre-loaded\n";

// EXTREME TEST 1: Ultra-high PUT volume
$put_perf = extreme_bench("EXTREME PUT TEST", 2000000, function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        if (!tagcache_put($client, "extreme_put_$i", "data_$i", [], 3600)) {
            return "PUT failed at $i";
        }
    }
    return true;
});

// EXTREME TEST 2: Ultra-high GET volume
$get_perf = extreme_bench("EXTREME GET TEST", 2000000, function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        $key = "preload_" . ($i % 100000); // Cycle through pre-loaded keys
        if (tagcache_get($client, $key) === null) {
            return "GET failed at $i";
        }
    }
    return true;
});

// EXTREME TEST 3: Massive bulk operations
$bulk_perf = extreme_bench("EXTREME BULK TEST", 2000000, function($n) use ($client) {
    $batch_size = 50000; // Larger batches
    $batches = (int)($n / $batch_size);
    
    for ($batch = 0; $batch < $batches; $batch++) {
        $items = [];
        for ($i = 0; $i < $batch_size; $i++) {
            $key = "mega_bulk_" . ($batch * $batch_size + $i);
            $items[$key] = "bulk_data_$i";
        }
        
        $result = tagcache_bulk_put($client, $items, 3600);
        if ($result !== $batch_size) {
            return "Bulk failed at batch $batch (got $result, expected $batch_size)";
        }
    }
    return true;
});

// EXTREME TEST 4: Concurrent-style workload simulation
$concurrent_perf = extreme_bench("EXTREME CONCURRENT SIM", 1000000, function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        $op = $i % 4;
        switch ($op) {
            case 0: // GET
                $key = "preload_" . ($i % 100000);
                if (tagcache_get($client, $key) === null) {
                    return "Concurrent GET failed at $i";
                }
                break;
            case 1: // PUT
                if (!tagcache_put($client, "concurrent_$i", "value_$i", [], 3600)) {
                    return "Concurrent PUT failed at $i";
                }
                break;
            case 2: // UPDATE existing
                $key = "preload_" . ($i % 100000);
                if (!tagcache_put($client, $key, "updated_$i", [], 3600)) {
                    return "Concurrent UPDATE failed at $i";
                }
                break;
            case 3: // DELETE
                if ($i % 1000 === 0) { // Occasional delete
                    tagcache_delete($client, "concurrent_" . ($i - 1000));
                }
                break;
        }
    }
    return true;
});

// EXTREME TEST 5: Large payload stress
$large_perf = extreme_bench("EXTREME LARGE PAYLOAD", 50000, function($n) use ($client) {
    $large_data = str_repeat("X", 8192); // 8KB payload
    
    for ($i = 0; $i < $n; $i++) {
        $key = "large_extreme_$i";
        if (!tagcache_put($client, $key, $large_data, [], 3600)) {
            return "Large PUT failed at $i";
        }
        
        $result = tagcache_get($client, $key);
        if ($result === null || strlen($result) !== 8192) {
            return "Large GET failed at $i";
        }
    }
    return true;
});

echo "\n" . str_repeat("=", 60) . "\n";
echo "🏆 EXTREME PERFORMANCE RESULTS\n";
echo str_repeat("=", 60) . "\n";

$results = [
    'PUT Operations' => $put_perf,
    'GET Operations' => $get_perf,
    'Bulk Operations' => $bulk_perf,
    'Concurrent Sim' => $concurrent_perf,
    'Large Payloads' => $large_perf
];

foreach ($results as $test => $perf) {
    $status = $perf >= 100000 ? "🔥" : ($perf >= 50000 ? "⚡" : "📈");
    printf("%-18s %s %s ops/sec\n", $test . ":", $status, number_format($perf, 0));
}

$peak = max($results);
echo "\n🎯 PEAK PERFORMANCE: " . number_format($peak, 0) . " ops/sec\n";

// Analysis vs targets
echo "\n📊 TARGET ANALYSIS:\n";
echo "Target Single Ops:  400,000 ops/sec\n";
echo "Target Bulk Ops:    500,000 ops/sec\n";
echo "Server Capacity:    500,000 ops/sec\n\n";

if ($put_perf >= 400000) {
    echo "✅ Single PUT: EXCEEDS TARGET\n";
} else {
    $gap = 400000 - $put_perf;
    echo "❌ Single PUT: " . number_format($gap, 0) . " ops/sec below target\n";
}

if ($get_perf >= 400000) {
    echo "✅ Single GET: EXCEEDS TARGET\n";
} else {
    $gap = 400000 - $get_perf;
    echo "❌ Single GET: " . number_format($gap, 0) . " ops/sec below target\n";
}

if ($bulk_perf >= 500000) {
    echo "✅ Bulk Ops: EXCEEDS TARGET\n";
} else {
    $gap = 500000 - $bulk_perf;
    echo "❌ Bulk Ops: " . number_format($gap, 0) . " ops/sec below target\n";
}

// Efficiency analysis
$server_efficiency = ($peak / 500000) * 100;
echo "\n🔬 EFFICIENCY ANALYSIS:\n";
printf("Server Utilization: %.1f%%\n", $server_efficiency);

if ($server_efficiency >= 80) {
    echo "🔥 EXCELLENT: Extension approaching server limits!\n";
} elseif ($server_efficiency >= 60) {
    echo "⚡ GOOD: Strong performance, room for optimization\n";
} else {
    echo "📈 NEEDS WORK: Significant optimization potential\n";
}

echo "\n🚀 NEXT OPTIMIZATION TARGETS:\n";
if ($put_perf < 400000 || $get_perf < 400000) {
    echo "1. 🎯 Connection pooling optimization\n";
    echo "2. 🎯 Ultra-fast serialization\n";
    echo "3. 🎯 Command pipelining\n";
    echo "4. 🎯 Memory allocation optimization\n";
}

tagcache_close($client);
echo "\n🔥 Extreme test complete!\n";
?>