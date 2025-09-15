<?php
ini_set('extension', '/Users/arogga/project/tagcache/php-ext/modules/tagcache.so');

echo "=== Quick Procedural API Verification ===\n";

$handle = tagcache_create(['host' => '127.0.0.1', 'port' => 1984]);
if (!$handle) {
    echo "ERROR: Failed to create connection\n";
    exit(1);
}

// Clear and test simple case
tagcache_flush($handle);

echo "Testing simple procedural invalidation...\n";
tagcache_put($handle, 'simple_test', 'value', ['test_tag'], 60000);
echo "Put item: " . (tagcache_get($handle, 'simple_test') ? "SUCCESS" : "FAILED") . "\n";

$count = tagcache_invalidate_tags_any($handle, ['test_tag']);
echo "Invalidated: $count items\n";
echo "Item after invalidation: " . (tagcache_get($handle, 'simple_test') ? "STILL EXISTS" : "GONE") . "\n";

tagcache_close($handle);

echo "\n=== Verification Complete ===\n";
?>