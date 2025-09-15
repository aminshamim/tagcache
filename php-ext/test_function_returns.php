<?php

echo "=== Testing Function Return Pattern ===\n\n";

function simulate_arogga_get($client, $key, &$local_cache) {
    if (array_key_exists($key, $local_cache)) {
        return $local_cache[$key];
    }
    
    $start = microtime(true);
    $value = $client->get($key);
    $time = (microtime(true) - $start) * 1000;
    
    if ($value !== null) {
        $local_cache[$key] = $value;
    }
    
    return ['value' => $value, 'time' => $time];  // This might be problematic
}

function simulate_arogga_set($client, $key, $value, $tags, &$local_cache) {
    $start = microtime(true);
    $result = $client->set($key, $value, $tags, 3600 * 1000);
    $time = (microtime(true) - $start) * 1000;
    
    if ($result) {
        $local_cache[$key] = $value;
    }
    
    return ['result' => $result, 'time' => $time];  // This might be problematic
}

$client = \TagCache::create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 36,
    'timeout_ms' => 10000,
]);

echo "Testing function return patterns...\n";

$local_cache = [];
$slow_operations = [];
$timeouts = 0;

// Test exactly the same loop as in reproduce_timeout.php
for ($i = 0; $i < 200; $i++) {
    $key = "user:session:" . ($i % 50);
    $value = json_encode([
        'user_id' => $i % 50,
        'session_data' => str_repeat('x', 500),
        'timestamp' => time(),
    ]);
    $tags = ['session', 'user:' . ($i % 50)];
    
    if ($i % 10 < 7) {
        // Read operation - using the function that returns array
        $result = simulate_arogga_get($client, $key, $local_cache);
        if (is_array($result) && $result['time'] > 100) {
            $slow_operations[] = ['op' => 'GET', 'key' => $key, 'time' => $result['time']];
        }
        if (is_array($result) && $result['time'] > 5000) {
            $timeouts++;
        }
    } else {
        // Write operation - using the function that returns array
        $result = simulate_arogga_set($client, $key, $value, $tags, $local_cache);
        if ($result['time'] > 100) {
            $slow_operations[] = ['op' => 'SET', 'key' => $key, 'time' => $result['time']];
        }
        if ($result['time'] > 5000) {
            $timeouts++;
        }
    }
    
    // Bulk operations every 25 iterations
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
    
    // Tag invalidation every 50 iterations
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
        
        $local_cache = [];
    }
    
    if ($i % 50 == 0) {
        echo "Completed $i iterations\n";
    }
}

echo "✅ Function return pattern test completed!\n";

?>