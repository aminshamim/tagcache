<?php
/**
 * Debug GET operations issue
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 4,
    'connect_timeout_ms' => 1000,
    'read_timeout_ms' => 1000
]);

if (!$client) {
    die("❌ Failed to connect\n");
}

echo "🔍 Debugging GET operations...\n\n";

// Test 1: Basic PUT/GET
echo "1. Testing basic PUT/GET:\n";
$key = "debug_test";
$value = "debug_value";

echo "   PUT '$key' = '$value'\n";
$put_result = tagcache_put($client, $key, $value, [], 3600);
echo "   PUT result: " . ($put_result ? "✅ SUCCESS" : "❌ FAILED") . "\n";

echo "   GET '$key'\n";
$get_result = tagcache_get($client, $key);
echo "   GET result: " . ($get_result === null ? "❌ NULL" : "✅ '$get_result'") . "\n";

// Test 2: Check if keys exist
echo "\n2. Testing key existence:\n";
for ($i = 0; $i < 5; $i++) {
    $test_key = "preload_$i";
    echo "   Checking '$test_key': ";
    $result = tagcache_get($client, $test_key);
    echo ($result === null ? "❌ NOT FOUND" : "✅ FOUND") . "\n";
}

// Test 3: Put some keys and immediately get them
echo "\n3. Testing immediate PUT/GET:\n";
for ($i = 0; $i < 3; $i++) {
    $key = "immediate_$i";
    $value = "value_$i";
    
    echo "   PUT '$key' = '$value': ";
    $put_ok = tagcache_put($client, $key, $value, [], 3600);
    echo ($put_ok ? "✅" : "❌") . " | ";
    
    echo "GET: ";
    $get_result = tagcache_get($client, $key);
    echo ($get_result === $value ? "✅ MATCH" : "❌ MISMATCH/NULL") . "\n";
}

// Test 4: Check server stats
echo "\n4. Server connection test:\n";
echo "   Client resource: " . get_resource_type($client) . "\n";

// Test 5: Simple performance check with working keys
echo "\n5. Performance test with verified keys:\n";
$verified_keys = [];
for ($i = 0; $i < 10; $i++) {
    $key = "perf_test_$i";
    $value = "perf_value_$i";
    if (tagcache_put($client, $key, $value, [], 3600)) {
        $verified_keys[] = $key;
    }
}

echo "   Created " . count($verified_keys) . " verified keys\n";

if (count($verified_keys) > 0) {
    echo "   Testing GET performance on verified keys...\n";
    $start = microtime(true);
    $success = 0;
    $total = 1000;
    
    for ($i = 0; $i < $total; $i++) {
        $key = $verified_keys[$i % count($verified_keys)];
        $result = tagcache_get($client, $key);
        if ($result !== null) $success++;
    }
    
    $end = microtime(true);
    $duration = $end - $start;
    
    if ($duration > 0) {
        $ops_per_sec = $total / $duration;
        echo "   Results: $success/$total successful in {$duration}s\n";
        echo "   Performance: " . number_format($ops_per_sec, 0) . " ops/sec\n";
    } else {
        echo "   ❌ Duration too small to measure\n";
    }
}

tagcache_close($client);
echo "\n✅ Debug complete\n";
?>