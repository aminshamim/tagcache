<?php

echo "🚀 Maximum Performance Recovery Attempt\n";
echo str_repeat("=", 80) . "\n";

// Try different configurations that might affect performance
$configs = [
    'default' => ['serializer' => 'native'],
    'optimized' => [
        'serializer' => 'native',
        'pool_size' => 32,
        'keep_alive' => true,
        'tcp_nodelay' => true,
        'timeout' => 0.1
    ],
    'ultra' => [
        'serializer' => 'native', 
        'pool_size' => 64,
        'keep_alive' => true,
        'tcp_nodelay' => true,
        'timeout' => 0.05
    ]
];

foreach ($configs as $config_name => $config) {
    echo "\n🔧 Testing Configuration: $config_name\n";
    echo str_repeat("-", 60) . "\n";
    
    $client = tagcache_create($config);
    if (!$client) {
        echo "Failed to create client\n";
        continue;
    }
    
    // Setup data - try different data sizes
    $small_data = [];
    $medium_data = [];
    $large_data = [];
    
    for ($i = 0; $i < 1000; $i++) {
        $small_data["s$i"] = "v$i";                    // ~5 bytes
        $medium_data["medium_$i"] = str_repeat("x", 50); // ~50 bytes
        $large_data["large_key_$i"] = str_repeat("data", 25); // ~100 bytes
    }
    
    // Bulk put all data
    tagcache_bulk_put($client, $small_data, 3600);
    tagcache_bulk_put($client, $medium_data, 3600);
    tagcache_bulk_put($client, $large_data, 3600);
    
    $small_keys = array_keys($small_data);
    $medium_keys = array_keys($medium_data);
    $large_keys = array_keys($large_data);
    
    // Test different batch sizes with different data sizes
    $batch_sizes = [100, 250, 500, 750, 1000];
    
    foreach (['small', 'medium', 'large'] as $data_type) {
        $keys = ${$data_type . '_keys'};
        echo "\n  📊 $data_type data:\n";
        
        foreach ($batch_sizes as $batch_size) {
            if ($batch_size > count($keys)) continue;
            
            $test_keys = array_slice($keys, 0, $batch_size);
            
            // Maximize iterations for accuracy
            $iterations = max(1, intval(100000 / $batch_size));
            $start = microtime(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                tagcache_bulk_get($client, $test_keys);
            }
            
            $duration = microtime(true) - $start;
            $total_ops = $iterations * $batch_size;
            $ops_per_sec = $total_ops / $duration;
            
            printf("    Batch %4d: %8.0f ops/sec (%6.2f μs/op)\n", 
                   $batch_size, $ops_per_sec, ($duration * 1000000) / $total_ops);
            
            // Break if we hit our target
            if ($ops_per_sec > 400000) {
                echo "    🎯 TARGET REACHED! Continuing...\n";
            }
        }
    }
    
    tagcache_close($client);
}

echo "\n🔬 Let's try even more aggressive optimization:\n";
echo str_repeat("=", 80) . "\n";

// Ultra-optimized test
$client = tagcache_create([
    'serializer' => 'native',
    'pool_size' => 128,  // Even larger pool
    'keep_alive' => true,
    'tcp_nodelay' => true,
    'timeout' => 0.01    // Very short timeout
]);

if ($client) {
    // Tiny keys and values for minimal protocol overhead
    $ultra_data = [];
    for ($i = 0; $i < 1000; $i++) {
        $ultra_data["$i"] = "$i";  // Minimal overhead
    }
    
    tagcache_bulk_put($client, $ultra_data, 3600);
    $ultra_keys = array_keys($ultra_data);
    
    echo "\n🚀 Ultra-optimized test (minimal overhead):\n";
    
    foreach ([500, 750, 1000] as $batch_size) {
        $test_keys = array_slice($ultra_keys, 0, $batch_size);
        
        // Maximum iterations
        $iterations = intval(500000 / $batch_size);
        $start = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            tagcache_bulk_get($client, $test_keys);
        }
        
        $duration = microtime(true) - $start;
        $total_ops = $iterations * $batch_size;
        $ops_per_sec = $total_ops / $duration;
        
        printf("Ultra Batch %4d: %8.0f ops/sec (%6.2f μs/op) [%d iters]\n", 
               $batch_size, $ops_per_sec, ($duration * 1000000) / $total_ops, $iterations);
    }
    
    tagcache_close($client);
}

echo "\n✅ Performance recovery attempt complete!\n";

?>