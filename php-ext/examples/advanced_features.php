<?php
/**
 * Advanced Features Example
 * 
 * This example demonstrates advanced TagCache features:
 * - Connection pooling and optimization
 * - Different serialization formats
 * - TTL management and expiration
 * - Tag-based data organization
 * - Error handling and recovery
 * - Performance monitoring
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension is not loaded!\n");
}

echo "🚀 TagCache Advanced Features Example\n";
echo "=====================================\n\n";

// Advanced configuration options
echo "🔧 Advanced Configuration:\n";
echo "--------------------------\n";

$configs = [
    'basic' => [
        'host' => '127.0.0.1',
        'port' => 1984,
        'timeout' => 1.0
    ],
    'optimized' => [
        'host' => '127.0.0.1', 
        'port' => 1984,
        'pool_size' => 32,
        'keep_alive' => true,
        'tcp_nodelay' => true,
        'timeout' => 0.5,
        'serialize_format' => 'native'
    ]
];

foreach ($configs as $name => $config) {
    echo "Testing $name configuration:\n";
    $client = tagcache_create($config);
    
    if ($client) {
        echo "  ✅ Connection successful\n";
        
        // Quick performance test
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            tagcache_put($client, "test:$i", "value $i", ['test'], 60);
        }
        $duration = microtime(true) - $start;
        echo "  📊 100 PUT ops: " . number_format($duration * 1000, 2) . " ms\n";
        
        tagcache_close($client);
    } else {
        echo "  ❌ Connection failed\n";
    }
    echo "\n";
}

// Use optimized client for remaining examples
$client = tagcache_create($configs['optimized']);
if (!$client) {
    die("❌ Failed to create optimized client\n");
}

// TTL and Expiration Management
echo "⏰ TTL and Expiration Management:\n";
echo "--------------------------------\n";

// Short TTL for demonstration
tagcache_put($client, 'ephemeral:data', 'This will expire soon', ['temp'], 2);
echo "Stored item with 2-second TTL\n";

// Verify it exists
$value = tagcache_get($client, 'ephemeral:data');
echo "Immediate retrieval: " . ($value ? "✅ Found: $value" : "❌ Not found") . "\n";

// Wait and check again
echo "Waiting 3 seconds for expiration...\n";
sleep(3);

$value = tagcache_get($client, 'ephemeral:data');
echo "After expiration: " . ($value ? "Still exists: $value" : "❌ Expired (expected)") . "\n\n";

// Different TTL strategies
$ttl_strategies = [
    'session:user:123' => 3600,      // 1 hour - user session
    'cache:api:response' => 300,     // 5 minutes - API cache
    'temp:processing' => 60,         // 1 minute - temporary processing
    'config:app' => 86400           // 24 hours - application config
];

echo "Implementing different TTL strategies:\n";
foreach ($ttl_strategies as $key => $ttl) {
    $value = "Data for " . explode(':', $key)[0];
    tagcache_put($client, $key, $value, [explode(':', $key)[0]], $ttl);
    echo "  $key: {$ttl}s TTL ✅\n";
}
echo "\n";

// Advanced Tag Management
echo "🏷️  Advanced Tag Management:\n";
echo "----------------------------\n";

// Hierarchical tagging system
$content_data = [
    'article:1' => ['title' => 'PHP Performance Tips', 'author' => 'John'],
    'article:2' => ['title' => 'Database Optimization', 'author' => 'Jane'],
    'article:3' => ['title' => 'Caching Strategies', 'author' => 'John'],
    'video:1' => ['title' => 'Intro to PHP', 'author' => 'Mike'],
    'video:2' => ['title' => 'Advanced Caching', 'author' => 'Jane']
];

echo "Storing content with hierarchical tags:\n";
foreach ($content_data as $key => $data) {
    list($type, $id) = explode(':', $key);
    $tags = [
        'content',           // All content
        "type:$type",       // Content type
        "author:{$data['author']}", // Author-specific
        'published'         // Publication status
    ];
    
    tagcache_put($client, $key, $data, $tags, 3600);
    echo "  $key: " . implode(', ', $tags) . " ✅\n";
}
echo "\n";

// Tag-based queries and invalidation
echo "Demonstrating tag-based operations:\n";

// Get all content by John
$johns_content = tagcache_get_keys_by_tag($client, 'author:John');
echo "Content by John: " . count($johns_content) . " items\n";

// Invalidate all videos
$invalidated = tagcache_invalidate_tag($client, 'type:video');
echo "Invalidated videos: $invalidated items\n";

// Verify articles still exist
$remaining_content = tagcache_get_keys_by_tag($client, 'content');
echo "Remaining content: " . count($remaining_content) . " items\n\n";

// Error Handling and Recovery
echo "⚠️  Error Handling and Recovery:\n";
echo "-------------------------------\n";

// Connection resilience test
function test_operation($client, $operation) {
    try {
        switch ($operation) {
            case 'put':
                return tagcache_put($client, 'test:resilience', 'test data', ['test'], 300);
            case 'get':
                return tagcache_get($client, 'test:resilience');
            case 'invalid':
                // This should fail gracefully
                return tagcache_put($client, '', '', [], -1);
        }
    } catch (Exception $e) {
        echo "    Exception caught: " . $e->getMessage() . "\n";
        return false;
    }
}

$operations = ['put', 'get', 'invalid'];
foreach ($operations as $op) {
    echo "Testing $op operation: ";
    $result = test_operation($client, $op);
    echo ($result !== false ? "✅ Success" : "❌ Failed") . "\n";
}
echo "\n";

// Performance Monitoring
echo "📊 Performance Monitoring:\n";
echo "--------------------------\n";

// Benchmark different operation types
$benchmarks = [];

// Single operations
$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    tagcache_put($client, "bench:single:$i", "data $i", ['benchmark'], 300);
}
$benchmarks['single_put'] = microtime(true) - $start;

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    tagcache_get($client, "bench:single:$i");
}
$benchmarks['single_get'] = microtime(true) - $start;

// Bulk operations
$bulk_data = [];
for ($i = 0; $i < 1000; $i++) {
    $bulk_data["bench:bulk:$i"] = "bulk data $i";
}

$start = microtime(true);
tagcache_bulk_put($client, $bulk_data, 300);
$benchmarks['bulk_put'] = microtime(true) - $start;

$start = microtime(true);
tagcache_bulk_get($client, array_keys($bulk_data));
$benchmarks['bulk_get'] = microtime(true) - $start;

// Display results
echo "Performance results (1000 operations each):\n";
foreach ($benchmarks as $operation => $duration) {
    $ops_per_sec = 1000 / $duration;
    echo "  $operation: " . number_format($duration * 1000, 2) . " ms (" . 
         number_format($ops_per_sec, 0) . " ops/sec)\n";
}

// Calculate efficiency ratios
$single_put_ops = 1000 / $benchmarks['single_put'];
$bulk_put_ops = 1000 / $benchmarks['bulk_put'];
$put_efficiency = $bulk_put_ops / $single_put_ops;

$single_get_ops = 1000 / $benchmarks['single_get'];
$bulk_get_ops = 1000 / $benchmarks['bulk_get'];
$get_efficiency = $bulk_get_ops / $single_get_ops;

echo "\nEfficiency gains:\n";
echo "  Bulk PUT: " . number_format($put_efficiency, 1) . "x faster\n";
echo "  Bulk GET: " . number_format($get_efficiency, 1) . "x faster\n\n";

// Memory usage analysis
echo "💾 Memory Usage Analysis:\n";
echo "------------------------\n";

$memory_before = memory_get_usage(true);
$peak_before = memory_get_peak_usage(true);

// Create a large dataset to monitor memory usage
$large_dataset = [];
for ($i = 0; $i < 10000; $i++) {
    $large_dataset["memory:test:$i"] = str_repeat("x", 100); // 100 bytes each
}

// Store in chunks to monitor memory
$chunk_size = 1000;
$chunks = array_chunk($large_dataset, $chunk_size, true);

foreach ($chunks as $chunk_num => $chunk) {
    tagcache_bulk_put($client, $chunk, 300);
    
    if ($chunk_num % 2 === 0) { // Monitor every other chunk
        $current_memory = memory_get_usage(true);
        $memory_increase = $current_memory - $memory_before;
        echo "  Chunk " . ($chunk_num + 1) . ": +" . 
             number_format($memory_increase / 1024 / 1024, 2) . " MB\n";
    }
}

$memory_after = memory_get_usage(true);
$peak_after = memory_get_peak_usage(true);

echo "Final memory stats:\n";
echo "  Memory increase: " . number_format(($memory_after - $memory_before) / 1024 / 1024, 2) . " MB\n";
echo "  Peak memory: " . number_format($peak_after / 1024 / 1024, 2) . " MB\n\n";

// Cleanup and connection management
echo "🧹 Cleanup and Connection Management:\n";
echo "------------------------------------\n";

// Clean up test data
$invalidated_benchmark = tagcache_invalidate_tag($client, 'benchmark');
$invalidated_memory = tagcache_invalidate_tag($client, 'memory');
echo "Cleaned up test data: " . ($invalidated_benchmark + $invalidated_memory) . " items\n";

// Properly close connection
tagcache_close($client);
echo "✅ Connection closed properly\n\n";

echo "🎉 Advanced features example completed!\n\n";
echo "💡 Advanced Tips:\n";
echo "  - Use connection pooling for high-concurrency applications\n";
echo "  - Implement hierarchical tagging for flexible data organization\n";
echo "  - Monitor memory usage in production environments\n";
echo "  - Use appropriate TTL values based on data access patterns\n";
echo "  - Always handle errors gracefully and close connections properly\n";
?>