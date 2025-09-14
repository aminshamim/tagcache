<?php
/**
 * Corrected Performance Benchmark
 * Fixes timing issues and key existence problems
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 16,
    'connect_timeout_ms' => 100,
    'read_timeout_ms' => 100
]);

if (!$client) {
    die("❌ Failed to connect\n");
}

function accurate_benchmark($name, $iterations, $callable) {
    echo "\n🚀 $name (" . number_format($iterations) . " ops)\n";
    echo str_repeat("=", 60) . "\n";
    
    // Use high-resolution timing
    $start = hrtime(true);
    $result = $callable($iterations);
    $end = hrtime(true);
    
    $duration_ns = $end - $start;
    $duration_s = $duration_ns / 1e9;
    
    // Prevent division by zero
    $ops_per_sec = $duration_s > 0 ? $iterations / $duration_s : 0;
    
    printf("⏱️  Duration: %.6fs\n", $duration_s);
    printf("🚀 Throughput: %s ops/sec\n", number_format($ops_per_sec, 0));
    printf("⚡ Latency: %.2fμs per op\n", ($duration_s * 1000000) / $iterations);
    printf("📊 Status: %s\n", $result === true ? "✅ PASSED" : "❌ FAILED: $result");
    
    return $ops_per_sec;
}

echo "🔥 CORRECTED PERFORMANCE BENCHMARK\n";
echo "Accurate timing with realistic test scenarios...\n";

// Pre-populate a reasonable amount of test data
echo "\n📝 Pre-populating test data...\n";
$test_key_count = 10000;
for ($i = 0; $i < $test_key_count; $i++) {
    tagcache_put($client, "bench_key_$i", "test_value_$i", [], 3600);
    if ($i % 1000 === 0) echo "  Created $i keys...\n";
}
echo "✅ $test_key_count keys created\n";

// Test 1: Single PUT operations (realistic scale)
$single_put_perf = accurate_benchmark("SINGLE PUT", 50000, function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        if (!tagcache_put($client, "put_test_$i", "data_$i", [], 3600)) {
            return "PUT failed at $i";
        }
    }
    return true;
});

// Test 2: Single GET operations (using existing keys)
$single_get_perf = accurate_benchmark("SINGLE GET", 50000, function($n) use ($client, $test_key_count) {
    for ($i = 0; $i < $n; $i++) {
        $key = "bench_key_" . ($i % $test_key_count);
        if (tagcache_get($client, $key) === null) {
            return "GET failed at $i for key $key";
        }
    }
    return true;
});

// Test 3: Bulk PUT operations
$bulk_perf = accurate_benchmark("BULK PUT", 100000, function($n) use ($client) {
    $batch_size = 1000;
    $batches = (int)($n / $batch_size);
    
    for ($batch = 0; $batch < $batches; $batch++) {
        $items = [];
        for ($i = 0; $i < $batch_size; $i++) {
            $key = "bulk_test_" . ($batch * $batch_size + $i);
            $items[$key] = "bulk_data_$i";
        }
        
        $result = tagcache_bulk_put($client, $items, 3600);
        if ($result !== $batch_size) {
            return "Bulk failed at batch $batch (got $result, expected $batch_size)";
        }
    }
    return true;
});

// Test 4: Mixed workload (using verified existing keys)
$mixed_perf = accurate_benchmark("MIXED WORKLOAD", 30000, function($n) use ($client, $test_key_count) {
    for ($i = 0; $i < $n; $i++) {
        $op = $i % 3;
        switch ($op) {
            case 0: // GET existing key
                $key = "bench_key_" . ($i % $test_key_count);
                if (tagcache_get($client, $key) === null) {
                    return "Mixed GET failed at $i for key $key";
                }
                break;
            case 1: // PUT new key
                if (!tagcache_put($client, "mixed_$i", "value_$i", [], 3600)) {
                    return "Mixed PUT failed at $i";
                }
                break;
            case 2: // GET previously created mixed key (if exists)
                if ($i > 100) { // Only after we've created some mixed keys
                    $prev_key = "mixed_" . ($i - 99);
                    tagcache_get($client, $prev_key); // Don't fail if not found
                }
                break;
        }
    }
    return true;
});

echo "\n" . str_repeat("=", 60) . "\n";
echo "🏆 CORRECTED PERFORMANCE RESULTS\n";
echo str_repeat("=", 60) . "\n";

$results = [
    'Single PUT' => $single_put_perf,
    'Single GET' => $single_get_perf,
    'Bulk PUT' => $bulk_perf,
    'Mixed Workload' => $mixed_perf
];

foreach ($results as $test => $perf) {
    $status = $perf >= 100000 ? "🔥🔥🔥" : ($perf >= 50000 ? "🔥🔥" : ($perf >= 25000 ? "🔥" : "📈"));
    printf("%-15s %s %s ops/sec\n", $test . ":", $status, number_format($perf, 0));
}

$peak = max($results);
$single_avg = ($single_put_perf + $single_get_perf) / 2;

echo "\n🎯 PERFORMANCE ANALYSIS:\n";
echo "Peak Throughput:    " . number_format($peak, 0) . " ops/sec\n";
echo "Single Ops Average: " . number_format($single_avg, 0) . " ops/sec\n";
echo "Bulk Operations:    " . number_format($bulk_perf, 0) . " ops/sec\n";

echo "\n📊 TARGET COMPARISON:\n";
echo "Target Single Ops:  400,000 ops/sec\n";
echo "Target Bulk Ops:    500,000 ops/sec\n";
echo "Server Capacity:    500,000 ops/sec\n\n";

if ($single_avg >= 40000) {
    echo "✅ SINGLE OPS: Strong performance (achieved " . number_format(($single_avg / 400000) * 100, 1) . "% of target)\n";
} else {
    echo "🎯 SINGLE OPS: Room for improvement (achieved " . number_format(($single_avg / 400000) * 100, 1) . "% of target)\n";
}

if ($bulk_perf >= 400000) {
    echo "✅ BULK OPS: Excellent performance (achieved " . number_format(($bulk_perf / 500000) * 100, 1) . "% of server capacity)\n";
} else {
    echo "🎯 BULK OPS: Good performance (achieved " . number_format(($bulk_perf / 500000) * 100, 1) . "% of server capacity)\n";
}

$efficiency = ($peak / 500000) * 100;
echo "\n🔬 EFFICIENCY ASSESSMENT:\n";
printf("Peak vs Server: %.1f%%\n", $efficiency);

if ($efficiency >= 80) {
    echo "🔥🔥🔥 OUTSTANDING: Extension approaching server limits!\n";
} elseif ($efficiency >= 60) {
    echo "🔥🔥 EXCELLENT: Strong performance\n";
} elseif ($efficiency >= 40) {
    echo "🔥 GOOD: Solid foundation\n";
} else {
    echo "📈 DEVELOPING: Room for optimization\n";
}

echo "\n✅ Accurate benchmark complete!\n";

tagcache_close($client);
?>