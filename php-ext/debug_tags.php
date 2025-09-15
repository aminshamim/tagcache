<?php
ini_set('extension', '/Users/arogga/project/tagcache/php-ext/modules/tagcache.so');

echo "=== Debugging Tag System ===\n\n";

$handle = tagcache_create(['host' => '127.0.0.1', 'port' => 1984]);
if (!$handle) {
    echo "ERROR: Failed to create connection\n";
    exit(1);
}

// Clear everything first
echo "Flushing cache...\n";
$count = tagcache_flush($handle);
echo "Flushed $count items\n\n";

// Test basic operations step by step
echo "Step 1: Setting a key with tags...\n";
$result = tagcache_put($handle, 'debug1', 'value1', ['debug_tag1', 'debug_tag2'], 60000);
echo "Put result: " . ($result ? "SUCCESS" : "FAILED") . "\n";

echo "\nStep 2: Retrieving the key...\n";
$val = tagcache_get($handle, 'debug1');
echo "Retrieved value: " . ($val ? $val : "NULL") . "\n";

echo "\nStep 3: Getting keys by tag...\n";
$keys = tagcache_keys_by_tag($handle, 'debug_tag1');
echo "Keys with debug_tag1: " . implode(', ', $keys) . "\n";

echo "\nStep 4: Testing single tag invalidation...\n";
$count = tagcache_invalidate_tag($handle, 'debug_tag1');
echo "Single tag invalidation count: $count\n";

// Check if the key still exists
$val = tagcache_get($handle, 'debug1');
echo "Value after single tag invalidation: " . ($val ? $val : "NULL") . "\n";

// Set up data for multi-tag test
echo "\nStep 5: Setting up multiple keys with different tag combinations...\n";
tagcache_put($handle, 'multi1', 'value1', ['tag_a', 'tag_b'], 60000);
tagcache_put($handle, 'multi2', 'value2', ['tag_b', 'tag_c'], 60000);
tagcache_put($handle, 'multi3', 'value3', ['tag_a', 'tag_c'], 60000);

// Check they exist
$val1 = tagcache_get($handle, 'multi1');
$val2 = tagcache_get($handle, 'multi2');
$val3 = tagcache_get($handle, 'multi3');
echo "multi1: " . ($val1 ? "EXISTS" : "NULL") . "\n";
echo "multi2: " . ($val2 ? "EXISTS" : "NULL") . "\n";
echo "multi3: " . ($val3 ? "EXISTS" : "NULL") . "\n";

echo "\nStep 6: Testing multi-tag ANY invalidation...\n";
$count = tagcache_invalidate_tags_any($handle, ['tag_a', 'tag_b']);
echo "Multi-tag ANY invalidation count: $count\n";

// Check what remains
$val1 = tagcache_get($handle, 'multi1');
$val2 = tagcache_get($handle, 'multi2');
$val3 = tagcache_get($handle, 'multi3');
echo "After ANY invalidation:\n";
echo "multi1: " . ($val1 ? "EXISTS" : "NULL") . " (should be NULL - had tag_a and tag_b)\n";
echo "multi2: " . ($val2 ? "EXISTS" : "NULL") . " (should be NULL - had tag_b)\n";
echo "multi3: " . ($val3 ? "EXISTS" : "NULL") . " (should be NULL - had tag_a)\n";

tagcache_close($handle);
echo "\nDebug test completed!\n";
?>