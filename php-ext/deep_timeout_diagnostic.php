<?php

echo "=== Deep Timeout Diagnostic Tool ===\n\n";

// Test 1: Raw TCP connection to verify server responsiveness
echo "1. Testing raw TCP connection...\n";
$start = microtime(true);
$sock = @fsockopen('127.0.0.1', 1984, $errno, $errstr, 5);
$connect_time = (microtime(true) - $start) * 1000;

if ($sock) {
    printf("✅ TCP connection established in %.2fms\n", $connect_time);
    
    // Test raw command
    $start = microtime(true);
    fwrite($sock, "STATS\n");
    $response = fgets($sock, 1024);
    $cmd_time = (microtime(true) - $start) * 1000;
    
    printf("✅ Raw STATS command completed in %.2fms\n", $cmd_time);
    printf("   Response: %s", $response);
    fclose($sock);
} else {
    printf("❌ TCP connection failed: %s (%d) in %.2fms\n", $errstr, $errno, $connect_time);
    echo "Is TagCache server running? Try: tagcache server\n";
    exit(1);
}

// Test 2: PHP Extension with minimal timeout
echo "\n2. Testing PHP extension with different timeouts...\n";

$timeout_tests = [100, 500, 1000, 2000, 5000, 10000];

foreach ($timeout_tests as $timeout) {
    echo "Testing with {$timeout}ms timeout...\n";
    
    try {
        $start = microtime(true);
        
        $client = tagcache_create([
            'mode' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 1984,
            'timeout_ms' => $timeout,
            'connect_timeout_ms' => $timeout,
            'pool_size' => 1, // Minimal pool for testing
        ]);
        
        $create_time = (microtime(true) - $start) * 1000;
        
        if (!$client) {
            printf("❌ Client creation failed in %.2fms\n", $create_time);
            continue;
        }
        
        printf("✅ Client created in %.2fms\n", $create_time);
        
        // Test simple operation
        $start = microtime(true);
        $result = tagcache_put($client, 'timeout_test', 'test_value', [], 5000);
        $put_time = (microtime(true) - $start) * 1000;
        
        if ($result) {
            printf("  ✅ PUT completed in %.2fms\n", $put_time);
        } else {
            printf("  ❌ PUT failed in %.2fms\n", $put_time);
        }
        
        // Test get operation
        $start = microtime(true);
        $value = tagcache_get($client, 'timeout_test');
        $get_time = (microtime(true) - $start) * 1000;
        
        if ($value !== null) {
            printf("  ✅ GET completed in %.2fms\n", $get_time);
        } else {
            printf("  ❌ GET failed in %.2fms\n", $get_time);
        }
        
        tagcache_close($client);
        
        // If this timeout works, we found the sweet spot
        if ($result && $value !== null && $put_time < ($timeout * 0.8)) {
            printf("  🎯 This timeout setting works well!\n");
            break;
        }
        
    } catch (Exception $e) {
        printf("❌ Exception with %dms timeout: %s\n", $timeout, $e->getMessage());
    }
    
    echo "\n";
}

// Test 3: Server load test
echo "3. Testing server under load...\n";

$start = microtime(true);
$client = tagcache_create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'timeout_ms' => 10000,
    'connect_timeout_ms' => 5000,
    'pool_size' => 4,
]);

if (!$client) {
    echo "❌ Failed to create client for load test\n";
    exit(1);
}

echo "Performing 50 rapid operations...\n";
$failures = 0;
$total_time = 0;
$slowest = 0;

for ($i = 0; $i < 50; $i++) {
    $op_start = microtime(true);
    
    $key = "load_test_$i";
    $value = "value_$i";
    $tags = ['load', 'test', "batch_" . intval($i / 10)];
    
    $success = tagcache_put($client, $key, $value, $tags, 10000);
    
    $op_time = (microtime(true) - $op_start) * 1000;
    $total_time += $op_time;
    
    if ($op_time > $slowest) {
        $slowest = $op_time;
    }
    
    if (!$success) {
        $failures++;
        printf("❌ Operation %d failed in %.2fms\n", $i, $op_time);
    } else if ($op_time > 100) {
        printf("⚠️  Operation %d slow: %.2fms\n", $i, $op_time);
    }
    
    // Brief pause to avoid overwhelming
    if ($i % 10 == 0) {
        usleep(1000); // 1ms pause
    }
}

$avg_time = $total_time / 50;
printf("\nLoad test results:\n");
printf("- Success rate: %.1f%% (%d failures)\n", ((50 - $failures) / 50) * 100, $failures);
printf("- Average time: %.2fms\n", $avg_time);
printf("- Slowest operation: %.2fms\n", $slowest);

// Test 4: Memory and connection analysis
echo "\n4. Connection pool analysis...\n";

// Create multiple clients to test pool behavior
$clients = [];
for ($i = 0; $i < 3; $i++) {
    $start = microtime(true);
    $client = tagcache_create([
        'mode' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1984,
        'timeout_ms' => 5000,
        'pool_size' => 2,
    ]);
    $create_time = (microtime(true) - $start) * 1000;
    
    if ($client) {
        $clients[] = $client;
        printf("Client %d created in %.2fms\n", $i + 1, $create_time);
    } else {
        printf("❌ Failed to create client %d\n", $i + 1);
    }
}

// Test concurrent operations
echo "\nTesting concurrent operations...\n";
foreach ($clients as $idx => $client) {
    $start = microtime(true);
    $result = tagcache_put($client, "concurrent_$idx", "value_$idx", ['concurrent'], 5000);
    $time = (microtime(true) - $start) * 1000;
    
    if ($result) {
        printf("✅ Concurrent client %d: %.2fms\n", $idx + 1, $time);
    } else {
        printf("❌ Concurrent client %d failed: %.2fms\n", $idx + 1, $time);
    }
}

// Cleanup
foreach ($clients as $client) {
    tagcache_close($client);
}

// Test 5: System resource check
echo "\n5. System resource analysis...\n";
printf("Memory usage: %.2f MB\n", memory_get_usage(true) / 1024 / 1024);
printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);

// Check open file descriptors (Unix only)
if (function_exists('exec') && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
    $pid = getmypid();
    $output = [];
    exec("lsof -p $pid 2>/dev/null | grep -c ':1984' || echo 0", $output);
    $open_connections = intval($output[0] ?? 0);
    printf("Open connections to port 1984: %d\n", $open_connections);
}

echo "\n=== Diagnostic Summary ===\n";

if ($failures > 5) {
    echo "🔥 HIGH FAILURE RATE - Likely server issues or network problems\n";
} else if ($avg_time > 50) {
    echo "🐌 SLOW OPERATIONS - Server may be under load or network latency\n";
} else if ($slowest > 500) {
    echo "⚠️  OCCASIONAL SLOWNESS - Some operations are taking too long\n";
} else {
    echo "✅ OPERATIONS LOOK NORMAL - Issue may be in specific usage patterns\n";
}

echo "\nRecommendations:\n";
if ($avg_time > 20) {
    echo "- Increase timeout_ms to at least " . (intval($slowest * 2)) . "ms\n";
}
if ($failures > 0) {
    echo "- Check server logs for errors\n";
    echo "- Verify server isn't overloaded\n";
    echo "- Consider connection pooling adjustments\n";
}
echo "- Monitor server with: tagcache stats\n";
echo "- Check server logs: journalctl -u tagcache -f\n";

?>