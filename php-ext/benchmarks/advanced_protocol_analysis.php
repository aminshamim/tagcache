<?php

// TagCache PHP Extension - Advanced Protocol Analysis
// Deep dive into network protocol efficiency and connection optimization

echo "TagCache PHP Extension - Advanced Protocol Analysis\n";
echo "====================================================\n\n";

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded!\n");
}

echo "🔍 INVESTIGATING NETWORK PROTOCOL EFFICIENCY\n";
echo str_repeat("=", 50) . "\n\n";

// Test different connection strategies
$connection_strategies = [
    'Single Connection' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 1,
        'enable_keep_alive' => true,
        'tcp_nodelay' => true,
    ],
    'Multiple Connections' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 50,  // Much larger pool
        'enable_keep_alive' => true,
        'tcp_nodelay' => true,
    ],
    'Async I/O Focus' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 20,
        'enable_keep_alive' => true,
        'enable_async_io' => true,
        'tcp_nodelay' => true,
        'connection_timeout' => 100,  // Very fast
        'read_timeout' => 100,
    ],
    'Pipelining Focus' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 10,
        'enable_keep_alive' => true,
        'enable_pipelining' => true,
        'tcp_nodelay' => true,
        'pipeline_buffer_size' => 8192,  // If supported
    ],
];

echo "1. CONNECTION STRATEGY COMPARISON\n";
echo str_repeat("-", 35) . "\n";

$strategy_results = [];

foreach ($connection_strategies as $strategy_name => $config) {
    echo "Testing: $strategy_name\n";
    
    $handle = tagcache_create($config);
    if (!$handle) {
        echo "❌ Failed to create handle for $strategy_name\n";
        continue;
    }
    
    // Test rapid-fire operations
    $operations = 10000;
    
    $start = microtime(true);
    for ($i = 0; $i < $operations; $i++) {
        tagcache_put($handle, "strategy_$i", "value", [], 3600);
    }
    $put_time = microtime(true) - $start;
    
    $start = microtime(true);
    for ($i = 0; $i < $operations; $i++) {
        tagcache_get($handle, "strategy_$i");
    }
    $get_time = microtime(true) - $start;
    
    $put_ops = $operations / $put_time;
    $get_ops = $operations / $get_time;
    $total_ops = ($operations * 2) / ($put_time + $get_time);
    
    $strategy_results[$strategy_name] = [
        'put_ops' => $put_ops,
        'get_ops' => $get_ops,
        'total_ops' => $total_ops
    ];
    
    printf("  PUT: %8.0f ops/sec\n", $put_ops);
    printf("  GET: %8.0f ops/sec\n", $get_ops);
    printf("  Combined: %8.0f ops/sec\n\n", $total_ops);
}

// Find best strategy
$best_strategy = array_keys($strategy_results, max($strategy_results))[0];
$best_performance = max(array_column($strategy_results, 'total_ops'));

echo sprintf("🏆 Best Strategy: %s (%.0f ops/sec)\n\n", $best_strategy, $best_performance);

// Test 2: Protocol Overhead Analysis
echo "2. PROTOCOL OVERHEAD ANALYSIS\n";
echo str_repeat("-", 32) . "\n";

// Use the best performing configuration
$optimal_handle = tagcache_create($connection_strategies[$best_strategy]);

// Test minimal operations to measure pure protocol overhead
$minimal_tests = [
    'Empty Value' => '',
    'Single Char' => 'A',
    'Small Value' => str_repeat('A', 10),
    'Medium Value' => str_repeat('A', 100),
    'Large Value' => str_repeat('A', 1000),
];

echo "Protocol overhead by payload size:\n";
printf("%-15s | %-10s | %-10s | %-10s\n", "Payload", "PUT μs", "GET μs", "Total μs");
echo str_repeat("-", 50) . "\n";

foreach ($minimal_tests as $test_name => $payload) {
    $iterations = 1000;
    
    // PUT timing
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        tagcache_put($optimal_handle, "overhead_$i", $payload, [], 3600);
    }
    $put_time = (microtime(true) - $start) / $iterations * 1000000; // microseconds
    
    // GET timing
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        tagcache_get($optimal_handle, "overhead_$i");
    }
    $get_time = (microtime(true) - $start) / $iterations * 1000000; // microseconds
    
    printf("%-15s | %8.1f | %8.1f | %8.1f\n", 
        $test_name, $put_time, $get_time, $put_time + $get_time);
}

// Test 3: Connection Reuse vs New Connections
echo "\n3. CONNECTION EFFICIENCY ANALYSIS\n";
echo str_repeat("-", 35) . "\n";

echo "Comparing connection reuse strategies:\n";

// Strategy 1: Single long-lived connection
$single_handle = tagcache_create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 1,
    'enable_keep_alive' => true,
]);

$iterations = 5000;
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    tagcache_get($single_handle, "reuse_test_" . ($i % 100));
}
$reuse_time = microtime(true) - $start;
$reuse_ops = $iterations / $reuse_time;

// Strategy 2: Connection per operation (worst case)
$start = microtime(true);
for ($i = 0; $i < 100; $i++) { // Fewer iterations due to overhead
    $temp_handle = tagcache_create([
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
    ]);
    tagcache_get($temp_handle, "new_test_$i");
}
$new_time = microtime(true) - $start;
$new_ops = 100 / $new_time;

printf("Connection reuse:    %8.0f ops/sec\n", $reuse_ops);
printf("New connection/op:   %8.0f ops/sec\n", $new_ops);
printf("Efficiency ratio:    %8.1fx\n", $reuse_ops / $new_ops);

// Test 4: Maximum Concurrent Connections
echo "\n4. MAXIMUM CONCURRENT CONNECTIONS\n";
echo str_repeat("-", 37) . "\n";

$pool_sizes = [1, 5, 10, 20, 50, 100];
$concurrent_results = [];

echo "Testing different pool sizes for maximum throughput:\n";
printf("%-10s | %-12s | %-12s\n", "Pool Size", "Ops/sec", "Efficiency");
echo str_repeat("-", 40) . "\n";

foreach ($pool_sizes as $pool_size) {
    $pool_handle = tagcache_create([
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => $pool_size,
        'enable_keep_alive' => true,
        'enable_async_io' => true,
        'tcp_nodelay' => true,
    ]);
    
    if (!$pool_handle) continue;
    
    $test_ops = 5000;
    $start = microtime(true);
    for ($i = 0; $i < $test_ops; $i++) {
        tagcache_get($pool_handle, "pool_test_" . ($i % 1000));
    }
    $pool_time = microtime(true) - $start;
    $pool_ops = $test_ops / $pool_time;
    $efficiency = $pool_ops / $pool_size; // Ops per connection
    
    $concurrent_results[$pool_size] = $pool_ops;
    
    printf("%-10d | %10.0f | %10.0f\n", $pool_size, $pool_ops, $efficiency);
}

$optimal_pool_size = array_search(max($concurrent_results), $concurrent_results);
echo "\nOptimal pool size: $optimal_pool_size connections\n";

// Test 5: Raw TCP Performance Measurement
echo "\n5. RAW TCP PERFORMANCE MEASUREMENT\n";
echo str_repeat("-", 37) . "\n";

// Test with absolutely minimal overhead
$raw_handle = tagcache_create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => $optimal_pool_size,
    'enable_keep_alive' => true,
    'enable_async_io' => true,
    'tcp_nodelay' => true,
    'connection_timeout' => 50,   // Minimal timeout
    'read_timeout' => 50,
]);

echo "Testing absolute maximum performance with optimal settings:\n";

// Pre-populate cache for maximum GET efficiency
echo "Pre-populating cache...\n";
for ($i = 0; $i < 1000; $i++) {
    tagcache_put($raw_handle, "raw_$i", "v", [], 3600);
}

// Maximum GET test
$max_operations = 50000;
echo "Testing $max_operations GET operations...\n";

$start = microtime(true);
for ($i = 0; $i < $max_operations; $i++) {
    tagcache_get($raw_handle, "raw_" . ($i % 1000));
}
$max_time = microtime(true) - $start;
$max_ops = $max_operations / $max_time;

printf("🚀 MAXIMUM GET Performance: %8.0f ops/sec\n", $max_ops);
printf("   Average latency: %8.1f μs/op\n", ($max_time / $max_operations) * 1000000);

// Compare with Rust benchmark
echo "\n6. COMPARISON WITH RUST PERFORMANCE\n";
echo str_repeat("-", 37) . "\n";

$rust_performance = 500000; // 500k ops/sec as mentioned
$php_performance = $max_ops;
$efficiency_ratio = ($php_performance / $rust_performance) * 100;

printf("Rust performance:     %8.0f ops/sec\n", $rust_performance);
printf("PHP Ext performance:  %8.0f ops/sec\n", $php_performance);
printf("Efficiency ratio:     %8.1f%%\n", $efficiency_ratio);

echo "\nPerformance gap analysis:\n";
if ($efficiency_ratio > 80) {
    echo "✅ Excellent - PHP extension is within 20% of Rust performance\n";
} elseif ($efficiency_ratio > 60) {
    echo "✅ Good - PHP extension is within 40% of Rust performance\n";
} elseif ($efficiency_ratio > 40) {
    echo "⚠️  Moderate - PHP extension is within 60% of Rust performance\n";
} else {
    echo "🔴 Poor - Significant performance gap exists\n";
}

// Test 7: Identify Bottlenecks
echo "\n7. BOTTLENECK IDENTIFICATION\n";
echo str_repeat("-", 30) . "\n";

echo "Potential bottlenecks and solutions:\n\n";

$latency_per_op = ($max_time / $max_operations) * 1000000; // microseconds

echo "Current per-operation latency: " . number_format($latency_per_op, 1) . " μs\n";
echo "Rust equivalent latency: " . number_format(1000000 / $rust_performance, 1) . " μs\n\n";

echo "Optimization opportunities:\n";

if ($latency_per_op > 5) {
    echo "🔴 MAJOR: Protocol overhead too high (>" . number_format($latency_per_op, 1) . "μs)\n";
    echo "   → Investigate TCP_NODELAY effectiveness\n";
    echo "   → Consider connection multiplexing\n";
    echo "   → Check serialization overhead\n\n";
}

if ($optimal_pool_size > 20) {
    echo "⚠️  Connection pool size suggests concurrency issues\n";
    echo "   → Implement true async I/O\n";
    echo "   → Consider event-driven architecture\n\n";
}

echo "📈 RECOMMENDATIONS FOR 500K+ OPS/SEC:\n";
echo str_repeat("=", 45) . "\n";
echo "1. 🔧 Implement connection multiplexing\n";
echo "2. 🔧 Use async I/O with event loops\n";
echo "3. 🔧 Minimize memory allocations per operation\n";
echo "4. 🔧 Implement request pipelining\n";
echo "5. 🔧 Optimize TCP socket options (TCP_NODELAY, SO_REUSEADDR)\n";
echo "6. 🔧 Consider UDP for fire-and-forget operations\n";
echo "7. 🔧 Implement batching at protocol level\n";
echo "8. 🔧 Use memory-mapped I/O if possible\n";

echo "\nAdvanced protocol analysis completed!\n";