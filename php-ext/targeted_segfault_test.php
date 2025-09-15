<?php

// More targeted segfault reproduction test
echo "=== Targeted Segfault Isolation ===\n\n";

$client = \TagCache::create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 36,
    'timeout_ms' => 10000,
]);

if (!$client) {
    echo "❌ Client creation failed\n";
    exit(1);
}

// Test 1: Rapid sequential operations (what might trigger the issue)
echo "1. Testing rapid sequential operations...\n";
for ($i = 0; $i < 100; $i++) {
    $key = "rapid_$i";
    $value = "value_$i";
    $tags = ['rapid', "batch_" . intval($i / 10)];
    
    $result = $client->set($key, $value, $tags, 60000);
    if (!$result) {
        echo "❌ SET failed at iteration $i\n";
        break;
    }
    
    if ($i % 20 == 0) {
        echo "  ✅ Completed $i operations\n";
    }
}
echo "✅ Rapid operations completed\n";

// Test 2: Mixed bulk operations that might cause corruption
echo "\n2. Testing mixed bulk operations pattern...\n";
$bulk_keys = [];
for ($i = 0; $i < 15; $i++) {
    $key = "mixed_bulk_$i";
    $bulk_keys[] = $key;
    $client->set($key, "bulk_value_$i", ['mixed', 'bulk'], 60000);
}

echo "  Doing bulk get...\n";
$results = $client->mGet($bulk_keys);
printf("  ✅ Retrieved %d items\n", count($results));

// Test 3: Tag invalidation after bulk operations
echo "\n3. Testing invalidation after bulk operations...\n";
$count = $client->invalidateTagsAny(['rapid']);
printf("  ✅ Invalidated %d rapid items\n", $count);

$count = $client->invalidateTagsAny(['mixed']);
printf("  ✅ Invalidated %d mixed items\n", $count);

// Test 4: The specific pattern from reproduce_timeout.php
echo "\n4. Testing the specific reproduction pattern...\n";

// Simulate session pattern (this might be the trigger)
for ($i = 0; $i < 50; $i++) {
    $user_id = ($i % 50) + 1;
    $session_key = "session:user:$user_id";
    $session_data = json_encode([
        'user_id' => $user_id,
        'session_data' => str_repeat('x', 500),
        'timestamp' => time(),
    ]);
    
    $result = $client->set($session_key, $session_data, ['session', "user:$user_id"], 1800000);
    if (!$result) {
        echo "❌ Session set failed at iteration $i\n";
        break;
    }
    
    // Every 10 iterations, do a bulk get
    if ($i % 10 == 0 && $i > 0) {
        $bulk_keys = [];
        for ($j = 1; $j <= 5; $j++) {
            $bulk_keys[] = "session:user:$j";
        }
        
        $bulk_results = $client->mGet($bulk_keys);
        // This might be where the corruption happens
        
        if ($i % 25 == 0) {
            // Tag invalidation
            $client->invalidateTagsAny(['session']);
        }
    }
}
echo "✅ Session pattern completed\n";

echo "\n5. Testing cleanup...\n";
// Final cleanup - this might trigger the destructor issue
echo "All tests completed without segfault!\n";

?>