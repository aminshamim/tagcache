<?php

// Simulate your AroggaCache usage pattern to reproduce the timeout
echo "=== AroggaCache Usage Pattern Simulation ===\n\n";

// Test your exact configuration (the broken one)
echo "1. Testing your original configuration...\n";

$your_config = [
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 36,
    'enable_keep_alive' => true,      // Invalid - will be ignored
    'enable_pipelining' => true,      // Invalid - will be ignored
    'enable_async_io' => true,        // Invalid - will be ignored
    'connection_timeout' => 1000,     // Invalid - will be ignored
    'read_timeout' => 1000,           // Invalid - will be ignored
];

$start = microtime(true);
try {
    $client = \TagCache::create($your_config);
    $create_time = (microtime(true) - $start) * 1000;
    
    if ($client) {
        printf("✅ Client created with your config in %.2fms\n", $create_time);
        
        // Test your typical operations
        $start = microtime(true);
        $result = $client->set('test_key', 'test_value', ['test'], 3600 * 1000);
        $set_time = (microtime(true) - $start) * 1000;
        
        if ($set_time > 100) {
            printf("⚠️  SET took %.2fms (slower than expected)\n", $set_time);
        } else {
            printf("✅ SET completed in %.2fms\n", $set_time);
        }
        
        $start = microtime(true);
        $value = $client->get('test_key');
        $get_time = (microtime(true) - $start) * 1000;
        
        if ($get_time > 50) {
            printf("⚠️  GET took %.2fms (slower than expected)\n", $get_time);
        } else {
            printf("✅ GET completed in %.2fms\n", $get_time);
        }
        
    } else {
        printf("❌ Client creation failed in %.2fms\n", $create_time);
    }
} catch (Exception $e) {
    printf("❌ Exception with your config: %s\n", $e->getMessage());
}

// Test 2: Simulate heavy concurrent usage like in production
echo "\n2. Simulating production-like concurrent usage...\n";

$fixed_config = [
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 36,
    'timeout_ms' => 10000,
    'connect_timeout_ms' => 5000,
];

$client = \TagCache::create($fixed_config);
if (!$client) {
    echo "❌ Failed to create client for concurrent test\n";
    exit(1);
}

echo "Simulating your typical usage pattern...\n";

// Simulate your get() method with local cache checks
function simulate_arogga_get($client, $key, &$local_cache) {
    // Check local first (your pattern)
    if (array_key_exists($key, $local_cache)) {
        return $local_cache[$key];
    }
    
    // Remote fetch
    $start = microtime(true);
    $value = $client->get($key);
    $time = (microtime(true) - $start) * 1000;
    
    if ($value !== null) {
        $local_cache[$key] = $value;
    }
    
    return ['value' => $value, 'time' => $time];
}

// Simulate your set() method
function simulate_arogga_set($client, $key, $value, $tags, &$local_cache) {
    $start = microtime(true);
    $result = $client->set($key, $value, $tags, 3600 * 1000);
    $time = (microtime(true) - $start) * 1000;
    
    if ($result) {
        $local_cache[$key] = $value;
    }
    
    return ['result' => $result, 'time' => $time];
}

$local_cache = [];
$slow_operations = [];
$timeouts = 0;

// Test pattern similar to your usage
for ($i = 0; $i < 200; $i++) {
    $key = "user:session:" . ($i % 50); // Simulate 50 concurrent users
    $value = json_encode([
        'user_id' => $i % 50,
        'session_data' => str_repeat('x', 500), // 500 bytes
        'timestamp' => time(),
    ]);
    $tags = ['session', 'user:' . ($i % 50)];
    
    // 70% reads, 30% writes (typical web app pattern)
    if ($i % 10 < 7) {
        // Read operation
        $result = simulate_arogga_get($client, $key, $local_cache);
        if (is_array($result) && $result['time'] > 100) {
            $slow_operations[] = ['op' => 'GET', 'key' => $key, 'time' => $result['time']];
        }
        if (is_array($result) && $result['time'] > 5000) {
            $timeouts++;
        }
    } else {
        // Write operation
        $result = simulate_arogga_set($client, $key, $value, $tags, $local_cache);
        if ($result['time'] > 100) {
            $slow_operations[] = ['op' => 'SET', 'key' => $key, 'time' => $result['time']];
        }
        if ($result['time'] > 5000) {
            $timeouts++;
        }
    }
    
    // Simulate some bulk operations (like your many() method)
    if ($i % 25 == 0) {
        $bulk_keys = [];
        for ($j = 0; $j < 5; $j++) {
            $bulk_keys[] = "bulk:" . (($i + $j) % 20);
        }
        
        $start = microtime(true);
        $bulk_results = $client->mGet($bulk_keys);
        $bulk_time = (microtime(true) - $start) * 1000;
        
        if ($bulk_time > 200) {
            $slow_operations[] = ['op' => 'BULK_GET', 'keys' => count($bulk_keys), 'time' => $bulk_time];
        }
        if ($bulk_time > 5000) {
            $timeouts++;
        }
    }
    
    // Simulate tag invalidation (like your clearTag method)
    if ($i % 50 == 0 && $i > 0) {
        $start = microtime(true);
        $count = $client->invalidateTagsAny(['session']);
        $invalidate_time = (microtime(true) - $start) * 1000;
        
        if ($invalidate_time > 500) {
            $slow_operations[] = ['op' => 'INVALIDATE', 'count' => $count, 'time' => $invalidate_time];
        }
        if ($invalidate_time > 5000) {
            $timeouts++;
        }
        
        // Clear local cache after invalidation (your pattern)
        $local_cache = [];
    }
}

echo "\n=== Production Simulation Results ===\n";
printf("Total operations: 200+\n");
printf("Slow operations (>100ms): %d\n", count($slow_operations));
printf("Timeout operations (>5s): %d\n", $timeouts);
printf("Local cache hits: %d\n", count($local_cache));

if (!empty($slow_operations)) {
    echo "\nSlow operations detected:\n";
    foreach (array_slice($slow_operations, 0, 10) as $op) {
        printf("- %s: %.2fms", $op['op'], $op['time']);
        if (isset($op['key'])) printf(" (key: %s)", substr($op['key'], 0, 30));
        if (isset($op['keys'])) printf(" (%d keys)", $op['keys']);
        if (isset($op['count'])) printf(" (%d items)", $op['count']);
        echo "\n";
    }
}

// Test 3: Test problematic scenarios
echo "\n3. Testing potential problematic scenarios...\n";

// Large data test
echo "Testing large data (10KB)...\n";
$large_data = str_repeat('x', 10000);
$start = microtime(true);
$result = $client->set('large_data_test', $large_data, ['large'], 60000);
$large_time = (microtime(true) - $start) * 1000;
printf("Large data SET: %.2fms %s\n", $large_time, $result ? '✅' : '❌');

// Many tags test
echo "Testing many tags (50 tags)...\n";
$many_tags = [];
for ($i = 0; $i < 50; $i++) {
    $many_tags[] = "tag_$i";
}
$start = microtime(true);
$result = $client->set('many_tags_test', 'value', $many_tags, 60000);
$tags_time = (microtime(true) - $start) * 1000;
printf("Many tags SET: %.2fms %s\n", $tags_time, $result ? '✅' : '❌');

// Rapid invalidation test
echo "Testing rapid invalidations...\n";
$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    $client->invalidateTagsAny(["rapid_$i"]);
}
$rapid_time = (microtime(true) - $start) * 1000;
printf("Rapid invalidations: %.2fms\n", $rapid_time);

echo "\n=== Analysis ===\n";

if ($timeouts > 0) {
    echo "🔥 TIMEOUT ISSUE REPRODUCED!\n";
    echo "Your application is experiencing actual timeouts.\n\n";
    
    echo "Likely causes:\n";
    echo "- Server overload during bulk operations\n";
    echo "- Network latency spikes\n";
    echo "- Large connection pool (36) may be overwhelming server\n";
    echo "- Rapid concurrent operations\n";
    
} else if (count($slow_operations) > 5) {
    echo "⚠️  SLOW OPERATIONS DETECTED\n";
    echo "Operations are slow but not timing out completely.\n\n";
    
    echo "Recommendations:\n";
    echo "- Increase timeout_ms to at least 15000ms\n";
    echo "- Reduce pool_size from 36 to 8-16\n";
    echo "- Add retry logic for failed operations\n";
    
} else {
    echo "✅ No significant issues detected in simulation\n";
    echo "The timeout may be occurring under specific conditions.\n\n";
    
    echo "To debug further:\n";
    echo "- Enable PHP error logging\n";
    echo "- Add timing logs around your actual problematic operations\n";
    echo "- Monitor server resources during peak usage\n";
}

?>