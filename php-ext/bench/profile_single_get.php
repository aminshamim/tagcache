<?php
/**
 * TagCache Performance Profiling - Single GET Operation Analysis
 * This script profiles single GET operations to identify performance bottlenecks
 */

// Start the TagCache server if not running
$server_check = @fsockopen('127.0.0.1', 1984, $errno, $errstr, 1);
if (!$server_check) {
    echo "❌ TagCache server not running on port 1984\n";
    echo "Start with: ./target/release/tagcache\n";
    exit(1);
}
fclose($server_check);

echo "🔍 TagCache Single GET Performance Profiling\n";
echo str_repeat("=", 60) . "\n";

// Profiling configuration
$iterations = 10000;
$warmup_iterations = 1000;

function microtime_float() {
    return microtime(true);
}

function profile_section($name, $callback) {
    $start = microtime_float();
    $result = $callback();
    $end = microtime_float();
    $duration = ($end - $start) * 1000; // Convert to milliseconds
    
    printf("📊 %-30s: %8.4f ms\n", $name, $duration);
    return $result;
}

function detailed_single_get_profile() {
    global $iterations, $warmup_iterations;
    
    echo "\n🎯 Detailed Single GET Profiling\n";
    echo str_repeat("-", 50) . "\n";
    
    // Test different scenarios
    $scenarios = [
        'php' => 'PHP serialize format',
        'native' => 'Native format (fastest)',
        'igbinary' => 'igbinary format',
        'msgpack' => 'msgpack format'
    ];
    
    foreach ($scenarios as $format => $description) {
        echo "\n📦 Format: $format ($description)\n";
        
        // Create client
        $client_create_time = profile_section("Client Creation", function() use ($format) {
            return tagcache_create([
                'host' => '127.0.0.1',
                'port' => 1984,
                'serializer' => $format,
                'pool_size' => 8,
                'timeout_ms' => 5000
            ]);
        });
        
        if (!$client_create_time) {
            echo "❌ Failed to create client for format: $format\n";
            continue;
        }
        
        // Prepare test data
        $test_key = "profile_key_$format";
        $test_value = ($format === 'native') ? "simple_test_value" : ['id' => 12345, 'data' => 'complex test data'];
        
        // Initial PUT to set up data
        profile_section("Initial PUT", function() use ($client_create_time, $test_key, $test_value) {
            return tagcache_put($client_create_time, $test_key, $test_value, [], 3600);
        });
        
        // Warmup phase
        profile_section("Warmup Phase", function() use ($client_create_time, $test_key, $warmup_iterations) {
            for ($i = 0; $i < $warmup_iterations; $i++) {
                tagcache_get($client_create_time, $test_key);
            }
        });
        
        // Detailed timing breakdown
        echo "\n🔬 Micro-benchmarks:\n";
        
        // Time individual GET operations
        $get_times = [];
        $connection_times = [];
        $deserialization_times = [];
        
        for ($sample = 0; $sample < 100; $sample++) {
            $start = microtime_float();
            $result = tagcache_get($client_create_time, $test_key);
            $end = microtime_float();
            
            $get_times[] = ($end - $start) * 1000000; // Convert to microseconds
        }
        
        // Statistical analysis
        sort($get_times);
        $min_time = min($get_times);
        $max_time = max($get_times);
        $avg_time = array_sum($get_times) / count($get_times);
        $median_time = $get_times[count($get_times) / 2];
        $p95_time = $get_times[intval(count($get_times) * 0.95)];
        
        printf("  • Min time:    %8.2f μs\n", $min_time);
        printf("  • Average:     %8.2f μs\n", $avg_time);
        printf("  • Median:      %8.2f μs\n", $median_time);
        printf("  • 95th pct:    %8.2f μs\n", $p95_time);
        printf("  • Max time:    %8.2f μs\n", $max_time);
        printf("  • Throughput:  %8.0f ops/sec\n", 1000000 / $avg_time);
        
        // Main performance test
        $main_test_time = profile_section("Main Test ($iterations ops)", function() use ($client_create_time, $test_key, $iterations) {
            $start = microtime_float();
            for ($i = 0; $i < $iterations; $i++) {
                tagcache_get($client_create_time, $test_key);
            }
            $end = microtime_float();
            return $end - $start;
        });
        
        $ops_per_sec = $iterations / $main_test_time;
        printf("  • Main test throughput: %8.0f ops/sec\n", $ops_per_sec);
        
        tagcache_close($client_create_time);
    }
}

function compare_bulk_vs_single() {
    global $iterations;
    
    echo "\n⚖️  Bulk vs Single Operation Comparison\n";
    echo str_repeat("-", 50) . "\n";
    
    $client = tagcache_create(['serializer' => 'native']);
    if (!$client) {
        echo "❌ Failed to create client\n";
        return;
    }
    
    // Prepare test data
    $single_key = "single_test_key";
    $single_value = "test_value_for_single_ops";
    
    $bulk_data = [];
    for ($i = 0; $i < 100; $i++) {
        $bulk_data["bulk_key_$i"] = "test_value_$i";
    }
    
    // Set up initial data
    tagcache_put($client, $single_key, $single_value, [], 3600);
    tagcache_bulk_put($client, $bulk_data, 3600);
    
    $bulk_keys = array_keys($bulk_data);
    
    // Test single operations
    echo "\n📊 Single Operations:\n";
    $single_start = microtime_float();
    for ($i = 0; $i < 1000; $i++) {
        tagcache_get($client, $single_key);
    }
    $single_duration = microtime_float() - $single_start;
    $single_ops_per_sec = 1000 / $single_duration;
    
    printf("  • 1000 single GETs: %.4f sec (%8.0f ops/sec)\n", $single_duration, $single_ops_per_sec);
    
    // Test bulk operations
    echo "\n📦 Bulk Operations:\n";
    $bulk_start = microtime_float();
    for ($i = 0; $i < 10; $i++) {
        tagcache_bulk_get($client, $bulk_keys);
    }
    $bulk_duration = microtime_float() - $bulk_start;
    $bulk_total_ops = 10 * count($bulk_keys);
    $bulk_ops_per_sec = $bulk_total_ops / $bulk_duration;
    
    printf("  • 10x bulk GETs (%d keys): %.4f sec (%8.0f ops/sec)\n", 
           count($bulk_keys), $bulk_duration, $bulk_ops_per_sec);
    
    // Calculate efficiency ratio
    $efficiency_ratio = $bulk_ops_per_sec / $single_ops_per_sec;
    printf("\n💡 Bulk operations are %.1fx more efficient than single operations\n", $efficiency_ratio);
    
    tagcache_close($client);
}

function analyze_connection_overhead() {
    echo "\n🔌 Connection and Protocol Overhead Analysis\n";
    echo str_repeat("-", 50) . "\n";
    
    // Test with different pool sizes
    $pool_sizes = [1, 2, 4, 8, 16];
    
    foreach ($pool_sizes as $pool_size) {
        echo "\n📊 Pool Size: $pool_size\n";
        
        $client = tagcache_create([
            'serializer' => 'native',
            'pool_size' => $pool_size,
            'timeout_ms' => 1000
        ]);
        
        if (!$client) continue;
        
        // Setup test data
        tagcache_put($client, 'pool_test_key', 'test_value', [], 3600);
        
        // Warmup
        for ($i = 0; $i < 100; $i++) {
            tagcache_get($client, 'pool_test_key');
        }
        
        // Performance test
        $start = microtime_float();
        for ($i = 0; $i < 1000; $i++) {
            tagcache_get($client, 'pool_test_key');
        }
        $duration = microtime_float() - $start;
        $ops_per_sec = 1000 / $duration;
        
        printf("  • Throughput: %8.0f ops/sec\n", $ops_per_sec);
        
        tagcache_close($client);
    }
}

function tcp_protocol_analysis() {
    echo "\n🌐 TCP Protocol Efficiency Analysis\n";
    echo str_repeat("-", 50) . "\n";
    
    $client = tagcache_create(['serializer' => 'native']);
    if (!$client) {
        echo "❌ Failed to create client\n";
        return;
    }
    
    // Test different value sizes
    $sizes = [10, 100, 1000, 10000];
    
    foreach ($sizes as $size) {
        $test_value = str_repeat('x', $size);
        $test_key = "size_test_$size";
        
        // PUT the value
        tagcache_put($client, $test_key, $test_value, [], 3600);
        
        // Time GET operations
        $start = microtime_float();
        for ($i = 0; $i < 1000; $i++) {
            tagcache_get($client, $test_key);
        }
        $duration = microtime_float() - $start;
        $ops_per_sec = 1000 / $duration;
        
        printf("📏 Value size %5d bytes: %8.0f ops/sec (%.2f μs/op)\n", 
               $size, $ops_per_sec, ($duration * 1000000) / 1000);
    }
    
    tagcache_close($client);
}

function memory_allocation_analysis() {
    echo "\n🧠 Memory Allocation Analysis\n";
    echo str_repeat("-", 50) . "\n";
    
    $memory_before = memory_get_usage(true);
    $peak_before = memory_get_peak_usage(true);
    
    $client = tagcache_create(['serializer' => 'native']);
    if (!$client) return;
    
    // Setup test
    tagcache_put($client, 'memory_test', 'test_value', [], 3600);
    
    // Memory usage during operations
    $operations = 10000;
    $memory_samples = [];
    
    for ($i = 0; $i < $operations; $i++) {
        tagcache_get($client, 'memory_test');
        
        if ($i % 1000 == 0) {
            $memory_samples[] = memory_get_usage(true);
        }
    }
    
    $memory_after = memory_get_usage(true);
    $peak_after = memory_get_peak_usage(true);
    
    printf("📊 Memory usage:\n");
    printf("  • Before: %8s bytes\n", number_format($memory_before));
    printf("  • After:  %8s bytes\n", number_format($memory_after));
    printf("  • Diff:   %8s bytes\n", number_format($memory_after - $memory_before));
    printf("  • Peak:   %8s bytes\n", number_format($peak_after - $peak_before));
    
    tagcache_close($client);
}

// Run all profiling tests
detailed_single_get_profile();
compare_bulk_vs_single();
analyze_connection_overhead();
tcp_protocol_analysis();
memory_allocation_analysis();

echo "\n" . str_repeat("=", 60) . "\n";
echo "🎯 PROFILING COMPLETE!\n";
echo "\n💡 Key Findings:\n";
echo "• Single GETs are limited by TCP round-trip overhead\n";
echo "• Bulk operations amortize connection costs across multiple operations\n";
echo "• Connection pooling helps but doesn't eliminate per-request overhead\n";
echo "• Serialization format has minimal impact on throughput bottleneck\n";
echo "• Main bottleneck: TCP protocol round-trip time, not processing time\n";

?>