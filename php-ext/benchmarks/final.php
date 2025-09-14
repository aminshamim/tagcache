<?php
/**
 * Final Performance Assessment - Maximum Scale Test
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 32,
    'connect_timeout_ms' => 50,
    'read_timeout_ms' => 50
]);

if (!$client) {
    die("❌ Failed to connect\n");
}

function final_benchmark($name, $iterations, $callable) {
    echo "\n🚀 $name (" . number_format($iterations) . " ops)\n";
    echo str_repeat("=", 60) . "\n";
    
    $start = microtime(true);
    $result = $callable($iterations);
    $end = microtime(true);
    
    $duration = $end - $start;
    $ops_per_sec = $duration > 0 ? $iterations / $duration : 0;
    
    printf("⏱️  Duration: %.3fs\n", $duration);
    printf("🚀 Throughput: %s ops/sec\n", number_format($ops_per_sec, 0));
    printf("⚡ Latency: %.2fμs per op\n", ($duration * 1000000) / $iterations);
    printf("📊 Status: %s\n", $result === true ? "✅ PASSED" : "❌ FAILED: $result");
    
    return $ops_per_sec;
}

echo "🔥 FINAL PERFORMANCE ASSESSMENT\n";
echo "Testing all optimizations at maximum scale...\n";

// Massive single operations test
$single_put_perf = final_benchmark("SINGLE PUT (5M ops)", 5000000, function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        if (!tagcache_put($client, "final_put_$i", "data_$i", [], 3600)) {
            return "PUT failed at $i";
        }
    }
    return true;
});

// Test GETs on existing data
$single_get_perf = final_benchmark("SINGLE GET (1M ops)", 1000000, function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        $key = "final_put_" . ($i % 1000000); // Cycle through existing keys
        if (tagcache_get($client, $key) === null) {
            return "GET failed at $i";
        }
    }
    return true;
});

// Massive bulk operations
$bulk_perf = final_benchmark("BULK PUT (5M items)", 5000000, function($n) use ($client) {
    $batch_size = 50000;
    $batches = (int)($n / $batch_size);
    
    for ($batch = 0; $batch < $batches; $batch++) {
        $items = [];
        for ($i = 0; $i < $batch_size; $i++) {
            $key = "final_bulk_" . ($batch * $batch_size + $i);
            $items[$key] = "bulk_data_$i";
        }
        
        $result = tagcache_bulk_put($client, $items, 3600);
        if ($result !== $batch_size) {
            return "Bulk failed at batch $batch (got $result, expected $batch_size)";
        }
    }
    return true;
});

// Mixed workload stress test
$mixed_perf = final_benchmark("MIXED WORKLOAD (2M ops)", 2000000, function($n) use ($client) {
    for ($i = 0; $i < $n; $i++) {
        $op = $i % 3;
        switch ($op) {
            case 0: // GET
                $key = "final_put_" . ($i % 1000000);
                if (tagcache_get($client, $key) === null) {
                    return "Mixed GET failed at $i";
                }
                break;
            case 1: // PUT
                if (!tagcache_put($client, "mixed_final_$i", "value_$i", [], 3600)) {
                    return "Mixed PUT failed at $i";
                }
                break;
            case 2: // DELETE (occasional)
                if ($i % 1000 === 0) {
                    tagcache_delete($client, "mixed_final_" . ($i - 1000));
                }
                break;
        }
    }
    return true;
});

echo "\n" . str_repeat("🔥", 20) . "\n";
echo "🏆 FINAL PERFORMANCE RESULTS\n";
echo str_repeat("🔥", 20) . "\n";

$results = [
    'Single PUT' => $single_put_perf,
    'Single GET' => $single_get_perf,
    'Bulk PUT' => $bulk_perf,
    'Mixed Workload' => $mixed_perf
];

foreach ($results as $test => $perf) {
    $status = $perf >= 400000 ? "🔥🔥🔥" : ($perf >= 100000 ? "🔥🔥" : ($perf >= 50000 ? "🔥" : "📈"));
    printf("%-15s %s %s ops/sec\n", $test . ":", $status, number_format($perf, 0));
}

$peak = max($results);
echo "\n🎯 PEAK PERFORMANCE: " . number_format($peak, 0) . " ops/sec\n";

// Final analysis
echo "\n📊 FINAL TARGET ANALYSIS:\n";
echo "Target Single Ops:  400,000 ops/sec\n";
echo "Target Bulk Ops:    500,000 ops/sec\n";
echo "Server Capacity:    500,000 ops/sec\n\n";

$single_ops_avg = ($single_put_perf + $single_get_perf) / 2;

if ($single_ops_avg >= 400000) {
    echo "✅ SINGLE OPS: TARGET ACHIEVED!\n";
} else {
    $gap = 400000 - $single_ops_avg;
    echo "🎯 SINGLE OPS: " . number_format($gap, 0) . " ops/sec from target (achieved " . number_format(($single_ops_avg / 400000) * 100, 1) . "%)\n";
}

if ($bulk_perf >= 500000) {
    echo "✅ BULK OPS: TARGET EXCEEDED!\n";
} else {
    $gap = 500000 - $bulk_perf;
    echo "🎯 BULK OPS: " . number_format($gap, 0) . " ops/sec from target (achieved " . number_format(($bulk_perf / 500000) * 100, 1) . "%)\n";
}

// Overall assessment
$server_efficiency = ($peak / 500000) * 100;
echo "\n🔬 OVERALL ASSESSMENT:\n";
printf("Server Utilization: %.1f%%\n", $server_efficiency);

if ($server_efficiency >= 80) {
    echo "🔥🔥🔥 OUTSTANDING: Extension performance approaching server limits!\n";
} elseif ($server_efficiency >= 60) {
    echo "🔥🔥 EXCELLENT: Strong performance with room for further optimization\n";
} else {
    echo "🔥 GOOD: Solid foundation, significant optimization opportunities remain\n";
}

echo "\n✅ Final assessment complete!\n";

tagcache_close($client);
?>