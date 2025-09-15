<?php

echo "=== Large Data Scenario Test (1MB+ values, 500+ tags) ===\n\n";

$client = \TagCache::create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 4,
    'timeout_ms' => 30000, // Very long timeout for large data
    'connect_timeout_ms' => 10000,
]);

if (!$client) {
    echo "❌ Failed to create client\n";
    exit(1);
}

echo "Testing scenarios similar to your production environment...\n\n";

// Test 1: Large array with 500+ children
echo "1. Testing large array with 500+ children...\n";
$large_array = [];
for ($i = 0; $i < 500; $i++) {
    $large_array["item_$i"] = [
        'id' => $i,
        'name' => "Item $i",
        'description' => str_repeat("This is a detailed description for item $i. ", 10),
        'metadata' => [
            'created_at' => time(),
            'updated_at' => time() + rand(1, 3600),
            'tags' => ['tag1', 'tag2', 'tag3'],
            'properties' => array_fill(0, 20, "property_value_$i")
        ],
        'data' => str_repeat("x", 100) // 100 bytes per item
    ];
}

$serialized_size = strlen(serialize($large_array));
printf("Large array size: %.2f KB (%d bytes)\n", $serialized_size / 1024, $serialized_size);

$start = microtime(true);
try {
    $result = $client->set('large_array_test', $large_array, ['large', 'array'], 300000);
    $time = (microtime(true) - $start) * 1000;
    printf("✅ Large array SET: %.2fms, success: %s\n", $time, $result ? 'Yes' : 'No');
} catch (Exception $e) {
    printf("❌ Large array SET failed: %s\n", $e->getMessage());
}

// Test 2: Very large value (1MB+)
echo "\n2. Testing 1MB+ value...\n";
$mb_data = [
    'metadata' => 'Large dataset',
    'data' => str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 30000), // ~1.08MB
    'array_data' => array_fill(0, 1000, str_repeat('x', 100)) // 100KB array
];

$mb_size = strlen(serialize($mb_data));
printf("1MB+ value size: %.2f MB (%d bytes)\n", $mb_size / 1024 / 1024, $mb_size);

$start = microtime(true);
try {
    $result = $client->set('mb_test', $mb_data, ['large', 'mb'], 300000);
    $time = (microtime(true) - $start) * 1000;
    printf("✅ 1MB+ SET: %.2fms, success: %s\n", $time, $result ? 'Yes' : 'No');
} catch (Exception $e) {
    printf("❌ 1MB+ SET failed: %s\n", $e->getMessage());
}

// Test 3: 500+ tags
echo "\n3. Testing 500+ tags...\n";
$many_tags = [];
for ($i = 0; $i < 500; $i++) {
    $many_tags[] = "tag_category_" . intval($i / 10) . "_item_$i";
}

$start = microtime(true);
try {
    $result = $client->set('many_tags_test', 'value_with_many_tags', $many_tags, 300000);
    $time = (microtime(true) - $start) * 1000;
    printf("✅ 500+ tags SET: %.2fms, success: %s\n", $time, $result ? 'Yes' : 'No');
} catch (Exception $e) {
    printf("❌ 500+ tags SET failed: %s\n", $e->getMessage());
}

// Test 4: Combined stress test (large value + many tags)
echo "\n4. Testing combined scenario (large value + many tags)...\n";
$combined_data = [
    'user_profiles' => [],
    'session_data' => [],
    'cache_data' => []
];

// Create realistic large dataset
for ($i = 0; $i < 200; $i++) {
    $combined_data['user_profiles'][$i] = [
        'user_id' => $i,
        'profile' => [
            'name' => "User $i",
            'email' => "user$i@example.com",
            'preferences' => array_fill(0, 50, "pref_$i"),
            'history' => array_fill(0, 100, "history_item_$i"),
            'metadata' => str_repeat("metadata_$i ", 50)
        ]
    ];
}

for ($i = 0; $i < 100; $i++) {
    $combined_data['session_data'][$i] = [
        'session_id' => "sess_$i",
        'data' => str_repeat("session_data_$i ", 100),
        'cart' => array_fill(0, 20, "cart_item_$i")
    ];
}

$combined_data['cache_data'] = str_repeat('Z', 200000); // 200KB of data

$combined_tags = [];
for ($i = 0; $i < 300; $i++) {
    $combined_tags[] = "combined_tag_$i";
}

$combined_size = strlen(serialize($combined_data));
printf("Combined data size: %.2f MB (%d bytes), %d tags\n", 
    $combined_size / 1024 / 1024, $combined_size, count($combined_tags));

$start = microtime(true);
try {
    $result = $client->set('combined_stress_test', $combined_data, $combined_tags, 300000);
    $time = (microtime(true) - $start) * 1000;
    printf("✅ Combined stress SET: %.2fms, success: %s\n", $time, $result ? 'Yes' : 'No');
} catch (Exception $e) {
    printf("❌ Combined stress SET failed: %s\n", $e->getMessage());
}

// Test 5: Retrieval of large data
echo "\n5. Testing retrieval of large data...\n";
$keys_to_test = ['large_array_test', 'mb_test', 'many_tags_test', 'combined_stress_test'];

foreach ($keys_to_test as $key) {
    $start = microtime(true);
    try {
        $value = $client->get($key);
        $time = (microtime(true) - $start) * 1000;
        $retrieved = ($value !== null);
        printf("- GET %s: %.2fms, retrieved: %s\n", $key, $time, $retrieved ? 'Yes' : 'No');
        
        if ($retrieved && is_array($value)) {
            $size = strlen(serialize($value));
            printf("  Retrieved size: %.2f KB\n", $size / 1024);
        }
    } catch (Exception $e) {
        printf("- GET %s failed: %s\n", $key, $e->getMessage());
    }
}

// Test 6: Bulk operations with large data
echo "\n6. Testing bulk operations with large data...\n";
$bulk_keys = [];
$bulk_data = [];

for ($i = 0; $i < 10; $i++) {
    $key = "bulk_large_$i";
    $bulk_keys[] = $key;
    
    // Create 100KB data per item
    $data = [
        'id' => $i,
        'large_field' => str_repeat("bulk_data_$i ", 2000), // ~20KB
        'array_field' => array_fill(0, 1000, "item_$i") // ~10KB
    ];
    
    $start = microtime(true);
    try {
        $result = $client->set($key, $data, ["bulk", "large", "item_$i"], 300000);
        $time = (microtime(true) - $start) * 1000;
        
        if (!$result || $time > 1000) {
            printf("⚠️  Bulk item %d: %.2fms, success: %s\n", $i, $time, $result ? 'Yes' : 'No');
        }
    } catch (Exception $e) {
        printf("❌ Bulk item %d failed: %s\n", $i, $e->getMessage());
    }
}

echo "Testing bulk GET...\n";
$start = microtime(true);
try {
    $bulk_results = $client->mGet($bulk_keys);
    $time = (microtime(true) - $start) * 1000;
    $retrieved_count = count(array_filter($bulk_results, fn($v) => $v !== null));
    printf("✅ Bulk GET: %.2fms, retrieved %d/%d items\n", 
        $time, $retrieved_count, count($bulk_keys));
} catch (Exception $e) {
    printf("❌ Bulk GET failed: %s\n", $e->getMessage());
}

// Test 7: Tag invalidation with many items
echo "\n7. Testing tag invalidation with many items...\n";
$start = microtime(true);
try {
    $count = $client->invalidateTagsAny(['large']);
    $time = (microtime(true) - $start) * 1000;
    printf("✅ Tag invalidation: %.2fms, %d items cleared\n", $time, $count);
} catch (Exception $e) {
    printf("❌ Tag invalidation failed: %s\n", $e->getMessage());
}

echo "\n=== Large Data Test Summary ===\n";
printf("Memory usage: %.2f MB (Peak: %.2f MB)\n", 
    memory_get_usage(true) / 1024 / 1024,
    memory_get_peak_usage(true) / 1024 / 1024);

echo "\nIf this test runs without segfault, the extension can handle large data!\n";

?>