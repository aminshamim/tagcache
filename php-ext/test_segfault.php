<?php

// Minimal reproduction to isolate the segfault cause
echo "=== Segfault Isolation Test ===\n\n";

echo "Testing basic operations that might cause segfault...\n";

$client = \TagCache::create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 36, // Your large pool size
    'timeout_ms' => 10000,
]);

if (!$client) {
    echo "❌ Client creation failed\n";
    exit(1);
}

// Test 1: Large pool size issue
echo "1. Testing large pool size (36 connections)...\n";
for ($i = 0; $i < 10; $i++) {
    $result = $client->set("pool_test_$i", "value_$i", ["pool"], 60000);
    if (!$result) {
        echo "❌ Pool test failed at iteration $i\n";
        break;
    }
}
echo "✅ Pool test completed\n";

// Test 2: Bulk operations that might cause buffer overflow
echo "2. Testing mGet bulk operations...\n";
$bulk_keys = [];
for ($i = 0; $i < 20; $i++) { // Smaller test first
    $bulk_keys[] = "bulk_key_$i";
    $client->set("bulk_key_$i", "value_$i", ["bulk"], 60000);
}

try {
    $results = $client->mGet($bulk_keys);
    echo "✅ mGet with 20 keys completed\n";
} catch (Exception $e) {
    echo "❌ mGet failed: " . $e->getMessage() . "\n";
}

// Test 3: Tag invalidation that might cause issues
echo "3. Testing tag invalidation...\n";
try {
    $count = $client->invalidateTagsAny(['bulk']);
    printf("✅ Invalidated %d items\n", $count);
} catch (Exception $e) {
    echo "❌ Invalidation failed: " . $e->getMessage() . "\n";
}

echo "\n=== If this runs without crash, segfault is in a specific pattern ===\n";

?>