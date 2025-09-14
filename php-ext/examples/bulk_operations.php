<?php
/**
 * Bulk Operations Example
 * 
 * This example demonstrates efficient bulk operations for high-performance scenarios:
 * - Bulk PUT operations
 * - Bulk GET operations  
 * - Performance comparisons
 * - Best practices for bulk data handling
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension is not loaded!\n");
}

echo "🚀 TagCache Bulk Operations Example\n";
echo "===================================\n\n";

// Create optimized client for bulk operations
$client = tagcache_create([
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 16,      // Connection pooling for better performance
    'keep_alive' => true,   // Reuse connections
    'tcp_nodelay' => true,  // Reduce latency
    'timeout' => 1.0
]);

if (!$client) {
    die("❌ Failed to connect to TagCache server\n");
}

echo "✅ Connected to TagCache server with optimized settings\n\n";

// Prepare bulk data
echo "📦 Preparing Bulk Data:\n";
echo "----------------------\n";

$bulk_data = [];
$batch_size = 100;

// Generate test data
for ($i = 1; $i <= $batch_size; $i++) {
    $bulk_data["item:$i"] = [
        'id' => $i,
        'name' => "Item $i",
        'description' => "This is test item number $i",
        'price' => round(rand(10, 1000) + (rand(0, 99) / 100), 2),
        'category' => ['electronics', 'gadgets', 'tech'][rand(0, 2)],
        'timestamp' => time()
    ];
}

echo "Generated $batch_size items for bulk operations\n\n";

// Bulk PUT operation
echo "📝 Bulk PUT Operations:\n";
echo "----------------------\n";

$start_time = microtime(true);
$success = tagcache_bulk_put($client, $bulk_data, 3600); // 1 hour TTL
$end_time = microtime(true);

$duration = ($end_time - $start_time) * 1000; // Convert to milliseconds
$ops_per_sec = $batch_size / ($end_time - $start_time);

echo "Bulk PUT result: " . ($success ? "✅ Success" : "❌ Failed") . "\n";
echo "Duration: " . number_format($duration, 2) . " ms\n";
echo "Throughput: " . number_format($ops_per_sec, 0) . " ops/sec\n\n";

// Bulk GET operation
echo "📖 Bulk GET Operations:\n";
echo "----------------------\n";

$keys = array_keys($bulk_data);

$start_time = microtime(true);
$retrieved_data = tagcache_bulk_get($client, $keys);
$end_time = microtime(true);

$duration = ($end_time - $start_time) * 1000;
$ops_per_sec = count($keys) / ($end_time - $start_time);
$hit_ratio = count($retrieved_data) / count($keys) * 100;

echo "Bulk GET result: Retrieved " . count($retrieved_data) . "/" . count($keys) . " items\n";
echo "Hit ratio: " . number_format($hit_ratio, 1) . "%\n";
echo "Duration: " . number_format($duration, 2) . " ms\n";
echo "Throughput: " . number_format($ops_per_sec, 0) . " ops/sec\n\n";

// Performance comparison: Single vs Bulk operations
echo "⚡ Performance Comparison:\n";
echo "-------------------------\n";

// Single operations benchmark
$single_keys = array_slice($keys, 0, 10); // Test with 10 items

$start_time = microtime(true);
foreach ($single_keys as $key) {
    tagcache_get($client, $key);
}
$single_duration = microtime(true) - $start_time;
$single_ops_per_sec = count($single_keys) / $single_duration;

// Bulk operation for same keys
$start_time = microtime(true);
$bulk_result = tagcache_bulk_get($client, $single_keys);
$bulk_duration = microtime(true) - $start_time;
$bulk_ops_per_sec = count($single_keys) / $bulk_duration;

$efficiency_gain = $bulk_ops_per_sec / $single_ops_per_sec;

echo "Single operations (10 items):\n";
echo "  Duration: " . number_format($single_duration * 1000, 2) . " ms\n";
echo "  Throughput: " . number_format($single_ops_per_sec, 0) . " ops/sec\n\n";

echo "Bulk operations (10 items):\n";
echo "  Duration: " . number_format($bulk_duration * 1000, 2) . " ms\n";
echo "  Throughput: " . number_format($bulk_ops_per_sec, 0) . " ops/sec\n\n";

echo "🚀 Efficiency gain: " . number_format($efficiency_gain, 1) . "x faster\n\n";

// Advanced bulk patterns
echo "🔄 Advanced Bulk Patterns:\n";
echo "--------------------------\n";

// Chunked bulk operations for very large datasets
$large_dataset = [];
for ($i = 1; $i <= 1000; $i++) {
    $large_dataset["large:$i"] = "Large dataset item $i";
}

$chunk_size = 50;
$chunks = array_chunk($large_dataset, $chunk_size, true);
$total_processed = 0;

echo "Processing " . count($large_dataset) . " items in chunks of $chunk_size:\n";

$start_time = microtime(true);
foreach ($chunks as $chunk_num => $chunk) {
    $success = tagcache_bulk_put($client, $chunk, 1800); // 30 minutes TTL
    if ($success) {
        $total_processed += count($chunk);
    }
    echo "  Chunk " . ($chunk_num + 1) . ": " . count($chunk) . " items " . 
         ($success ? "✅" : "❌") . "\n";
}
$total_time = microtime(true) - $start_time;

echo "Total processed: $total_processed items\n";
echo "Total time: " . number_format($total_time * 1000, 2) . " ms\n";
echo "Average throughput: " . number_format($total_processed / $total_time, 0) . " ops/sec\n\n";

// Bulk operations with tags
echo "🏷️  Bulk Operations with Tags:\n";
echo "-----------------------------\n";

$tagged_data = [];
$categories = ['urgent', 'normal', 'low'];

for ($i = 1; $i <= 30; $i++) {
    $category = $categories[($i - 1) % 3];
    $tagged_data["task:$i"] = [
        'title' => "Task $i",
        'priority' => $category,
        'created' => time()
    ];
}

// Store with tags (Note: bulk_put doesn't support per-item tags, so we store them individually)
foreach ($tagged_data as $key => $data) {
    $tags = ['tasks', $data['priority']];
    tagcache_put($client, $key, $data, $tags, 1800);
}

echo "Stored 30 tasks with priority tags\n";

// Demonstrate tag-based invalidation
$invalidated = tagcache_invalidate_tag($client, 'urgent');
echo "Invalidated $invalidated urgent tasks\n\n";

// Memory usage monitoring
echo "💾 Memory Usage:\n";
echo "---------------\n";
echo "Peak memory usage: " . number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Current memory usage: " . number_format(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n\n";

// Clean up
tagcache_close($client);

echo "🎉 Bulk operations example completed!\n\n";
echo "💡 Key Takeaways:\n";
echo "  - Bulk operations are 5-10x faster than individual operations\n";
echo "  - Use connection pooling and keep-alive for best performance\n";
echo "  - Process large datasets in chunks to manage memory\n";
echo "  - Consider tag-based organization for efficient invalidation\n";
?>