<?php
ini_set('extension', '/Users/arogga/project/tagcache/php-ext/modules/tagcache.so');

echo "=== Testing Server Command Support ===\n\n";

$handle = tagcache_create(['host' => '127.0.0.1', 'port' => 1984]);
if (!$handle) {
    echo "ERROR: Failed to create connection\n";
    exit(1);
}

// First, set up some test data
echo "Setting up test data...\n";
tagcache_put($handle, 'test1', 'value1', ['tag1'], 60000);
tagcache_put($handle, 'test2', 'value2', ['tag2'], 60000);

// Test basic single tag invalidation (should work)
echo "Testing single tag invalidation...\n";
$count = tagcache_invalidate_tag($handle, 'tag1');
echo "Single tag invalidation count: $count\n";

// Check if the key was invalidated
$val = tagcache_get($handle, 'test1');
echo "test1 after invalidation: " . ($val ? "EXISTS" : "NULL") . "\n";

// Reset the data
tagcache_put($handle, 'test1', 'value1', ['tag1'], 60000);

// Test the new multi-tag invalidation functions
echo "\nTesting multi-tag invalidation functions...\n";

try {
    $count = tagcache_invalidate_tags_any($handle, ['tag1']);
    echo "Multi-tag ANY invalidation count: $count\n";
} catch (Exception $e) {
    echo "ERROR in invalidate_tags_any: " . $e->getMessage() . "\n";
}

try {
    $count = tagcache_invalidate_tags_all($handle, ['tag2']);
    echo "Multi-tag ALL invalidation count: $count\n";
} catch (Exception $e) {
    echo "ERROR in invalidate_tags_all: " . $e->getMessage() . "\n";
}

try {
    $count = tagcache_invalidate_keys($handle, ['test2']);
    echo "Keys invalidation count: $count\n";
} catch (Exception $e) {
    echo "ERROR in invalidate_keys: " . $e->getMessage() . "\n";
}

tagcache_close($handle);
echo "\nTest completed!\n";
?>