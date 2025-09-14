<?php

echo "🔬 Performance Methodology Comparison\n";
echo str_repeat("=", 80) . "\n";

$client = tagcache_create(['serializer' => 'native']);
if (!$client) {
    die("Failed to create client\n");
}

// Setup bulk data
$bulk_data = [];
for ($i = 0; $i < 500; $i++) {
    $bulk_data["perf_test_$i"] = str_repeat("x", 100); // 100 byte values
}
tagcache_bulk_put($client, $bulk_data, 3600);
$keys = array_keys($bulk_data);

echo "\n📊 Method 1: Original tcp_bottleneck_analysis approach\n";
echo str_repeat("-", 60) . "\n";

$batch_sizes = [10, 50, 100, 250, 500];

foreach ($batch_sizes as $batch_size) {
    $test_keys = array_slice($keys, 0, $batch_size);
    
    $start = microtime(true);
    $iterations = intval(10000 / $batch_size); // More iterations for accuracy
    
    for ($i = 0; $i < $iterations; $i++) {
        tagcache_bulk_get($client, $test_keys);
    }
    
    $duration = microtime(true) - $start;
    $total_ops = $iterations * $batch_size;
    $ops_per_sec = $total_ops / $duration;
    
    printf("Batch %3d: %8.0f ops/sec (%6.2f μs/op) [%d iterations]\n", 
           $batch_size, $ops_per_sec, ($duration * 1000000) / $total_ops, $iterations);
}

echo "\n📊 Method 2: Stress test approach (fixed operations count)\n";
echo str_repeat("-", 60) . "\n";

foreach ($batch_sizes as $batch_size) {
    $test_keys = array_slice($keys, 0, $batch_size);
    
    $iterations = 100; // Fixed iterations like stress test
    $start = microtime(true);
    
    for ($i = 0; $i < $iterations; $i++) {
        tagcache_bulk_get($client, $test_keys);
    }
    
    $duration = microtime(true) - $start;
    $total_ops = $iterations * $batch_size;
    $ops_per_sec = $total_ops / $duration;
    
    printf("Batch %3d: %8.0f ops/sec (%6.2f μs/op) [%d iterations]\n", 
           $batch_size, $ops_per_sec, ($duration * 1000000) / $total_ops, $iterations);
}

echo "\n📊 Method 3: Pure bulk throughput test (minimize overhead)\n";
echo str_repeat("-", 60) . "\n";

foreach ($batch_sizes as $batch_size) {
    $test_keys = array_slice($keys, 0, $batch_size);
    
    // Minimize measurement overhead - single large batch
    $start = microtime(true);
    $large_iterations = intval(50000 / $batch_size);
    
    for ($i = 0; $i < $large_iterations; $i++) {
        tagcache_bulk_get($client, $test_keys);
    }
    
    $duration = microtime(true) - $start;
    $total_ops = $large_iterations * $batch_size;
    $ops_per_sec = $total_ops / $duration;
    
    printf("Batch %3d: %8.0f ops/sec (%6.2f μs/op) [%d iterations]\n", 
           $batch_size, $ops_per_sec, ($duration * 1000000) / $total_ops, $large_iterations);
}

tagcache_close($client);

echo "\n🎯 ANALYSIS:\n";
echo str_repeat("=", 80) . "\n";
echo "• Method 1: Variable iterations, optimizes for batch efficiency\n";
echo "• Method 2: Fixed iterations, measures sustained performance\n";
echo "• Method 3: Large iterations, measures pure throughput\n";
echo "\n✅ Method 3 should show the 500K+ ops/sec you remember!\n";

?>