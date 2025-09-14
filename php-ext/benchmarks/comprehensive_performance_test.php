<?php
// Comprehensive performance test for all TagCache optimizations

if (!extension_loaded('tagcache')) {
    echo "TagCache extension not loaded\n";
    exit(1);
}

function format_number($num) {
    return number_format($num);
}

function benchmark_operation($name, $handle, $operation, $iterations = 10000) {
    echo "\n=== $name ===\n";
    gc_collect_cycles();
    
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $operation($handle, $i);
    }
    $end = microtime(true);
    
    $total_time = ($end - $start);
    $ops_per_sec = $iterations / $total_time;
    $avg_latency = ($total_time / $iterations) * 1000; // ms
    
    echo "Iterations: " . format_number($iterations) . "\n";
    echo "Total time: " . sprintf("%.3f", $total_time) . "s\n";
    echo "Ops/sec: " . format_number($ops_per_sec) . "\n";
    echo "Avg latency: " . sprintf("%.3f", $avg_latency) . "ms\n";
    
    return $ops_per_sec;
}

function test_single_get($handle, $i) {
    $key = "test_" . ($i % 1000); // Repeat keys for cache hits
    tagcache_get($handle, $key);
}

function test_bulk_get($handle, $i) {
    $keys = [];
    for ($j = 0; $j < 10; $j++) {
        $keys[] = "bulk_" . (($i * 10 + $j) % 1000);
    }
    tagcache_bulk_get($handle, $keys);
}

function test_put_operations($handle, $i) {
    $key = "put_test_$i";
    $value = "value_$i";
    tagcache_put($handle, $key, $value, [], 300);
}

echo "TagCache PHP Extension - Comprehensive Performance Test\n";
echo "======================================================\n";

// Test configurations
$configs = [
    'Basic TCP' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 1,
    ],
    'Optimized TCP' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 8,
        'enable_keep_alive' => true,
        'keep_alive_idle' => 30,
        'keep_alive_interval' => 5,
        'keep_alive_count' => 3,
    ],
    'TCP + Pipelining' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 8,
        'enable_keep_alive' => true,
        'enable_pipelining' => true,
        'pipeline_depth' => 20,
    ],
    'Full Optimizations' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 8,
        'enable_keep_alive' => true,
        'enable_pipelining' => true,
        'pipeline_depth' => 20,
        'enable_async_io' => true,
    ],
];

$results = [];

foreach ($configs as $config_name => $config) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "Testing Configuration: $config_name\n";
    echo str_repeat("=", 60) . "\n";
    
    $handle = tagcache_create($config);
    if (!$handle) {
        echo "Failed to create handle for $config_name\n";
        continue;
    }
    
    // Setup test data
    echo "\nSetting up test data...\n";
    for ($i = 0; $i < 1000; $i++) {
        tagcache_put($handle, "test_$i", "value_$i", [], 300);
        tagcache_put($handle, "bulk_$i", "bulk_value_$i", [], 300);
    }
    
    // Test single GET operations
    $single_get_ops = benchmark_operation("Single GET Operations", $handle, 'test_single_get', 5000);
    
    // Test bulk GET operations  
    $bulk_get_ops = benchmark_operation("Bulk GET Operations (10 keys)", $handle, 'test_bulk_get', 500);
    
    // Test PUT operations
    $put_ops = benchmark_operation("PUT Operations", $handle, 'test_put_operations', 2000);
    
    $results[$config_name] = [
        'single_get' => $single_get_ops,
        'bulk_get' => $bulk_get_ops,
        'put' => $put_ops,
    ];
    
    // Clean up test data
    for ($i = 0; $i < 2000; $i++) {
        tagcache_delete($handle, "put_test_$i");
    }
}

// Results summary
echo "\n" . str_repeat("=", 80) . "\n";
echo "PERFORMANCE COMPARISON SUMMARY\n";
echo str_repeat("=", 80) . "\n";

echo sprintf("%-20s | %-15s | %-15s | %-15s\n", "Configuration", "Single GET", "Bulk GET", "PUT");
echo str_repeat("-", 80) . "\n";

$baseline = null;
foreach ($results as $config_name => $ops) {
    if ($baseline === null) {
        $baseline = $ops;
    }
    
    echo sprintf("%-20s | %13s/s | %13s/s | %13s/s\n", 
        $config_name,
        format_number($ops['single_get']),
        format_number($ops['bulk_get']),
        format_number($ops['put'])
    );
}

// Performance improvements
echo "\n" . str_repeat("=", 80) . "\n";
echo "PERFORMANCE IMPROVEMENTS vs Basic TCP\n";
echo str_repeat("=", 80) . "\n";

echo sprintf("%-20s | %-15s | %-15s | %-15s\n", "Configuration", "Single GET", "Bulk GET", "PUT");
echo str_repeat("-", 80) . "\n";

foreach ($results as $config_name => $ops) {
    if ($config_name === 'Basic TCP') continue;
    
    $single_improvement = ($ops['single_get'] / $baseline['single_get']) * 100;
    $bulk_improvement = ($ops['bulk_get'] / $baseline['bulk_get']) * 100;
    $put_improvement = ($ops['put'] / $baseline['put']) * 100;
    
    echo sprintf("%-20s | %13.1f%% | %13.1f%% | %13.1f%%\n", 
        $config_name,
        $single_improvement,
        $bulk_improvement,
        $put_improvement
    );
}

// Cache hit rate analysis (if cache is enabled)
echo "\n" . str_repeat("=", 60) . "\n";
echo "OPTIMIZATION ANALYSIS\n";
echo str_repeat("=", 60) . "\n";

// Find best performing configuration
$best_config = '';
$best_score = 0;
foreach ($results as $config_name => $ops) {
    $score = $ops['single_get'] + $ops['bulk_get'] + $ops['put'];
    if ($score > $best_score) {
        $best_score = $score;
        $best_config = $config_name;
    }
}

echo "Best performing configuration: $best_config\n";
echo "Combined score: " . format_number($best_score) . " ops/sec\n";

// Calculate overall improvement
$baseline_score = $baseline['single_get'] + $baseline['bulk_get'] + $baseline['put'];
$improvement = ($best_score / $baseline_score - 1) * 100;
echo "Overall improvement: " . sprintf("%.1f", $improvement) . "%\n";

echo "\nOptimizations impact:\n";
echo "- Keep-alive: Reduces connection overhead\n";
echo "- Pipelining: Batches multiple operations\n";
echo "- Connection pooling: Parallel processing capability\n";
echo "- Async I/O: Non-blocking operations for better concurrency\n";

echo "\nTest completed!\n";
?>