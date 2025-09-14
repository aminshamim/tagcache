<?php

// TagCache PHP Extension - Performance Profiler
// Identifies bottlenecks and generates flame graph-like analysis

echo "TagCache PHP Extension - Performance Profiler\n";
echo "==============================================\n\n";

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded!\n");
}

// Enable detailed timing
declare(ticks=1);

class PerformanceProfiler {
    private $profiles = [];
    private $stack = [];
    private $start_time;
    
    public function __construct() {
        $this->start_time = microtime(true);
    }
    
    public function start($operation) {
        $this->stack[] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage()
        ];
    }
    
    public function end($operation) {
        if (empty($this->stack)) return;
        
        $frame = array_pop($this->stack);
        if ($frame['operation'] !== $operation) {
            echo "Warning: Mismatched profiling operation: {$frame['operation']} != $operation\n";
            return;
        }
        
        $duration = microtime(true) - $frame['start_time'];
        $memory_delta = memory_get_usage() - $frame['start_memory'];
        
        if (!isset($this->profiles[$operation])) {
            $this->profiles[$operation] = [
                'calls' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0,
                'total_memory' => 0,
                'times' => []
            ];
        }
        
        $profile = &$this->profiles[$operation];
        $profile['calls']++;
        $profile['total_time'] += $duration;
        $profile['min_time'] = min($profile['min_time'], $duration);
        $profile['max_time'] = max($profile['max_time'], $duration);
        $profile['total_memory'] += $memory_delta;
        $profile['times'][] = $duration;
    }
    
    public function getResults() {
        $results = [];
        foreach ($this->profiles as $operation => $profile) {
            $avg_time = $profile['total_time'] / $profile['calls'];
            $times = $profile['times'];
            sort($times);
            $median_time = $times[intval(count($times) / 2)];
            
            // Calculate percentiles
            $p95_idx = intval(count($times) * 0.95);
            $p99_idx = intval(count($times) * 0.99);
            $p95_time = $times[$p95_idx] ?? $avg_time;
            $p99_time = $times[$p99_idx] ?? $avg_time;
            
            $results[$operation] = [
                'calls' => $profile['calls'],
                'total_time' => $profile['total_time'],
                'avg_time' => $avg_time,
                'median_time' => $median_time,
                'min_time' => $profile['min_time'],
                'max_time' => $profile['max_time'],
                'p95_time' => $p95_time,
                'p99_time' => $p99_time,
                'total_memory' => $profile['total_memory'],
                'avg_memory' => $profile['total_memory'] / $profile['calls'],
                'ops_per_sec' => $profile['calls'] / $profile['total_time']
            ];
        }
        
        return $results;
    }
    
    public function printFlameGraph() {
        $results = $this->getResults();
        
        echo "Performance Flame Graph Analysis\n";
        echo str_repeat("=", 50) . "\n";
        
        // Sort by total time (hottest first)
        uasort($results, function($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });
        
        $total_time = array_sum(array_column($results, 'total_time'));
        
        printf("%-25s | %8s | %10s | %8s | %8s | %8s\n", 
            "Operation", "Calls", "Total(ms)", "Avg(μs)", "P95(μs)", "Ops/sec");
        echo str_repeat("-", 85) . "\n";
        
        foreach ($results as $operation => $data) {
            $percentage = ($data['total_time'] / $total_time) * 100;
            $bar_length = max(0, min(25, intval($percentage / 2))); // Ensure valid range
            $bar = str_repeat("█", $bar_length) . str_repeat("░", max(0, 25 - $bar_length));
            
            printf("%-25s | %8d | %8.2f | %8.1f | %8.1f | %8.0f\n",
                substr($operation, 0, 25),
                $data['calls'],
                $data['total_time'] * 1000,
                $data['avg_time'] * 1000000,
                $data['p95_time'] * 1000000,
                $data['ops_per_sec']
            );
            
            echo "  $bar " . sprintf("%.1f%%", $percentage) . "\n";
        }
    }
    
    public function printDetailedAnalysis() {
        $results = $this->getResults();
        
        echo "\nDetailed Performance Analysis\n";
        echo str_repeat("=", 50) . "\n";
        
        foreach ($results as $operation => $data) {
            echo "\n$operation:\n";
            echo "  Calls: {$data['calls']}\n";
            echo "  Total Time: " . sprintf("%.3f ms", $data['total_time'] * 1000) . "\n";
            echo "  Average Time: " . sprintf("%.1f μs", $data['avg_time'] * 1000000) . "\n";
            echo "  Median Time: " . sprintf("%.1f μs", $data['median_time'] * 1000000) . "\n";
            echo "  Min Time: " . sprintf("%.1f μs", $data['min_time'] * 1000000) . "\n";
            echo "  Max Time: " . sprintf("%.1f μs", $data['max_time'] * 1000000) . "\n";
            echo "  P95 Time: " . sprintf("%.1f μs", $data['p95_time'] * 1000000) . "\n";
            echo "  P99 Time: " . sprintf("%.1f μs", $data['p99_time'] * 1000000) . "\n";
            echo "  Throughput: " . sprintf("%.0f ops/sec", $data['ops_per_sec']) . "\n";
            echo "  Memory per op: " . sprintf("%.1f bytes", $data['avg_memory']) . "\n";
        }
    }
}

// Test configurations
$configs = [
    'Basic TCP' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
    ],
    'Optimized' => [
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'pool_size' => 1, // Single connection for profiling
        'enable_keep_alive' => true,
        'enable_async_io' => false, // Disable for clearer profiling
        'enable_pipelining' => false,
    ],
];

function profiledTagcacheOperation($profiler, $handle, $operation, $key, $value = null, $tags = [], $ttl = 3600) {
    $profiler->start($operation);
    
    switch ($operation) {
        case 'PUT':
            $result = tagcache_put($handle, $key, $value, $tags, $ttl);
            break;
        case 'GET':
            $result = tagcache_get($handle, $key);
            break;
        case 'DELETE':
            $result = tagcache_delete($handle, $key);
            break;
        case 'FLUSH':
            $result = tagcache_flush($handle);
            break;
        default:
            $result = false;
    }
    
    $profiler->end($operation);
    return $result;
}

echo "Running detailed performance profiling...\n\n";

foreach ($configs as $config_name => $config) {
    echo "Profiling Configuration: $config_name\n";
    echo str_repeat("-", 40) . "\n";
    
    $profiler = new PerformanceProfiler();
    
    // Create handle
    $profiler->start('HANDLE_CREATE');
    $handle = tagcache_create($config);
    $profiler->end('HANDLE_CREATE');
    
    if (!$handle) {
        echo "❌ Failed to create handle\n";
        continue;
    }
    
    // Warm up
    echo "Warming up...\n";
    for ($i = 0; $i < 100; $i++) {
        profiledTagcacheOperation($profiler, $handle, 'PUT', "warmup_$i", "value_$i", ['warmup']);
    }
    
    // Profile different operations
    $test_operations = [
        'PUT' => 5000,
        'GET' => 5000,
    ];
    
    foreach ($test_operations as $op => $count) {
        echo "Profiling $op operations ($count iterations)...\n";
        
        for ($i = 0; $i < $count; $i++) {
            $key = "profile_key_" . ($i % 1000);
            
            if ($op === 'PUT') {
                $value = "profile_value_$i";
                profiledTagcacheOperation($profiler, $handle, $op, $key, $value, ['profile']);
            } else {
                profiledTagcacheOperation($profiler, $handle, $op, $key);
            }
            
            // Add some variety in timing
            if ($i % 1000 == 0) {
                usleep(10); // 10 microseconds every 1000 ops
            }
        }
    }
    
    // Profile bulk operations
    echo "Profiling bulk operations...\n";
    $bulk_keys = [];
    for ($i = 0; $i < 100; $i++) {
        $bulk_keys[] = "profile_key_$i";
    }
    
    for ($i = 0; $i < 100; $i++) {
        $profiler->start('BULK_GET');
        $results = tagcache_bulk_get($handle, $bulk_keys);
        $profiler->end('BULK_GET');
    }
    
    // Profile cleanup
    $profiler->start('INVALIDATE_TAG');
    tagcache_invalidate_tag($handle, 'profile');
    $profiler->end('INVALIDATE_TAG');
    
    $profiler->start('FLUSH');
    tagcache_flush($handle);
    $profiler->end('FLUSH');
    
    // Print results
    $profiler->printFlameGraph();
    $profiler->printDetailedAnalysis();
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
}

// Additional micro-benchmarks
echo "Micro-benchmark Analysis\n";
echo str_repeat("=", 30) . "\n";

$handle = tagcache_create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 1,
]);

// Test different payload sizes
$payload_sizes = [10, 100, 1000, 10000];
echo "\nPayload Size Impact:\n";
printf("%-12s | %-10s | %-10s\n", "Size (bytes)", "PUT ops/s", "GET ops/s");
echo str_repeat("-", 35) . "\n";

foreach ($payload_sizes as $size) {
    $payload = str_repeat('A', $size);
    
    // PUT test
    $start = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        tagcache_put($handle, "size_test_$i", $payload, [], 3600);
    }
    $put_time = microtime(true) - $start;
    $put_ops = 1000 / $put_time;
    
    // GET test
    $start = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        tagcache_get($handle, "size_test_$i");
    }
    $get_time = microtime(true) - $start;
    $get_ops = 1000 / $get_time;
    
    printf("%-12d | %-10.0f | %-10.0f\n", $size, $put_ops, $get_ops);
}

// Test connection overhead
echo "\nConnection Overhead Analysis:\n";
$iterations = 100;

// Single connection reuse
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    tagcache_put($handle, "reuse_$i", "value", [], 3600);
    tagcache_get($handle, "reuse_$i");
}
$reuse_time = microtime(true) - $start;

// New connection each time
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $new_handle = tagcache_create([
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
    ]);
    tagcache_put($new_handle, "new_$i", "value", [], 3600);
    tagcache_get($new_handle, "new_$i");
}
$new_time = microtime(true) - $start;

echo "Connection reuse: " . sprintf("%.1f ops/sec", ($iterations * 2) / $reuse_time) . "\n";
echo "New connections: " . sprintf("%.1f ops/sec", ($iterations * 2) / $new_time) . "\n";
echo "Overhead factor: " . sprintf("%.1fx", $new_time / $reuse_time) . "\n";

echo "\nProfiling completed!\n";
echo "\nBottleneck Analysis Recommendations:\n";
echo "1. Check TCP connection overhead\n";
echo "2. Analyze serialization/deserialization costs\n";
echo "3. Review network latency impact\n";
echo "4. Consider connection pooling effectiveness\n";
echo "5. Examine memory allocation patterns\n";