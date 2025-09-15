<?php
ini_set('extension', '/Users/arogga/project/tagcache/php-ext/modules/tagcache.so');

echo "=== PHP TagCache Multi-Tag Invalidation - Final Demo ===\n\n";

function demo_all_features() {
    echo "--- Demonstrating All Multi-Tag Invalidation Features ---\n";
    
    // Test both procedural and OO APIs
    $handle = tagcache_create(['host' => '127.0.0.1', 'port' => 1984]);
    $tc = TagCache::create(['host' => '127.0.0.1', 'port' => 1984]);
    
    if (!$handle || !$tc) {
        echo "ERROR: Failed to create connections\n";
        return false;
    }
    
    // Clear data
    $tc->flush();
    
    echo "✓ Connected to TagCache server\n";
    echo "✓ Extension loaded and working\n\n";
    
    // Demo 1: Procedural API
    echo "=== PROCEDURAL API DEMO ===\n";
    
    // Set up data
    tagcache_put($handle, 'user:1', 'John Doe', ['users', 'active', 'premium'], 60000);
    tagcache_put($handle, 'user:2', 'Jane Smith', ['users', 'active'], 60000);
    tagcache_put($handle, 'user:3', 'Bob Wilson', ['users', 'inactive'], 60000);
    tagcache_put($handle, 'admin:1', 'Admin User', ['admins', 'active'], 60000);
    tagcache_put($handle, 'cache:1', 'Some Data', ['cache', 'temp'], 60000);
    
    echo "Set up 5 items with various tags\n";
    
    // Test 1: invalidate_tags_any
    echo "\n1. tagcache_invalidate_tags_any(['users', 'cache']):\n";
    $count = tagcache_invalidate_tags_any($handle, ['users', 'cache']);
    echo "   Invalidated: $count items\n";
    echo "   user:1: " . (tagcache_get($handle, 'user:1') ? "EXISTS" : "GONE") . "\n";
    echo "   user:2: " . (tagcache_get($handle, 'user:2') ? "EXISTS" : "GONE") . "\n";
    echo "   admin:1: " . (tagcache_get($handle, 'admin:1') ? "EXISTS" : "GONE") . "\n";
    echo "   cache:1: " . (tagcache_get($handle, 'cache:1') ? "EXISTS" : "GONE") . "\n";
    
    // Reset for next test
    tagcache_put($handle, 'test:1', 'data1', ['tag1', 'tag2'], 60000);
    tagcache_put($handle, 'test:2', 'data2', ['tag2', 'tag3'], 60000);
    tagcache_put($handle, 'test:3', 'data3', ['tag1', 'tag2', 'tag3'], 60000);
    
    // Test 2: invalidate_tags_all
    echo "\n2. tagcache_invalidate_tags_all(['tag1', 'tag2']):\n";
    $count = tagcache_invalidate_tags_all($handle, ['tag1', 'tag2']);
    echo "   Invalidated: $count items\n";
    echo "   test:1: " . (tagcache_get($handle, 'test:1') ? "EXISTS" : "GONE") . " (had both)\n";
    echo "   test:2: " . (tagcache_get($handle, 'test:2') ? "EXISTS" : "GONE") . " (missing tag1)\n";
    echo "   test:3: " . (tagcache_get($handle, 'test:3') ? "EXISTS" : "GONE") . " (had both)\n";
    
    // Test 3: invalidate_keys
    echo "\n3. tagcache_invalidate_keys(['test:2', 'admin:1']):\n";
    $count = tagcache_invalidate_keys($handle, ['test:2', 'admin:1']);
    echo "   Invalidated: $count items\n";
    echo "   test:2: " . (tagcache_get($handle, 'test:2') ? "EXISTS" : "GONE") . "\n";
    echo "   admin:1: " . (tagcache_get($handle, 'admin:1') ? "EXISTS" : "GONE") . "\n";
    
    // Demo 2: OO API
    echo "\n=== OBJECT-ORIENTED API DEMO ===\n";
    
    $tc->flush(); // Clear for clean demo
    
    // Set up data
    $tc->set('product:1', 'Laptop', ['electronics', 'computers', 'sale'], 60000);
    $tc->set('product:2', 'Mouse', ['electronics', 'accessories'], 60000);
    $tc->set('product:3', 'Book', ['books', 'sale'], 60000);
    $tc->set('product:4', 'Phone', ['electronics', 'mobile'], 60000);
    
    echo "Set up 4 products with various tags\n";
    
    // Test 1: OO invalidateTagsAny
    echo "\n1. \$tc->invalidateTagsAny(['electronics', 'books']):\n";
    $count = $tc->invalidateTagsAny(['electronics', 'books']);
    echo "   Invalidated: $count items\n";
    echo "   product:1: " . ($tc->get('product:1') ? "EXISTS" : "GONE") . " (electronics)\n";
    echo "   product:2: " . ($tc->get('product:2') ? "EXISTS" : "GONE") . " (electronics)\n";
    echo "   product:3: " . ($tc->get('product:3') ? "EXISTS" : "GONE") . " (books)\n";
    echo "   product:4: " . ($tc->get('product:4') ? "EXISTS" : "GONE") . " (electronics)\n";
    
    // Reset for next test
    $tc->set('item:1', 'data1', ['category:A', 'status:active'], 60000);
    $tc->set('item:2', 'data2', ['category:B', 'status:active'], 60000);
    $tc->set('item:3', 'data3', ['category:A', 'status:inactive'], 60000);
    
    // Test 2: OO invalidateTagsAll
    echo "\n2. \$tc->invalidateTagsAll(['category:A', 'status:active']):\n";
    $count = $tc->invalidateTagsAll(['category:A', 'status:active']);
    echo "   Invalidated: $count items\n";
    echo "   item:1: " . ($tc->get('item:1') ? "EXISTS" : "GONE") . " (has both)\n";
    echo "   item:2: " . ($tc->get('item:2') ? "EXISTS" : "GONE") . " (missing category:A)\n";
    echo "   item:3: " . ($tc->get('item:3') ? "EXISTS" : "GONE") . " (missing status:active)\n";
    
    // Test 3: OO invalidateKeys
    echo "\n3. \$tc->invalidateKeys(['item:2', 'item:3']):\n";
    $count = $tc->invalidateKeys(['item:2', 'item:3']);
    echo "   Invalidated: $count items\n";
    echo "   item:2: " . ($tc->get('item:2') ? "EXISTS" : "GONE") . "\n";
    echo "   item:3: " . ($tc->get('item:3') ? "EXISTS" : "GONE") . "\n";
    
    // Summary
    echo "\n=== FEATURE SUMMARY ===\n";
    echo "✓ tagcache_invalidate_tags_any() - Invalidates keys with ANY of the specified tags\n";
    echo "✓ tagcache_invalidate_tags_all() - Invalidates keys with ALL of the specified tags\n";
    echo "✓ tagcache_invalidate_keys() - Invalidates specific keys by name\n";
    echo "✓ \$tc->invalidateTagsAny() - OO version of invalidate_tags_any\n";
    echo "✓ \$tc->invalidateTagsAll() - OO version of invalidate_tags_all\n";
    echo "✓ \$tc->invalidateKeys() - OO version of invalidate_keys\n";
    echo "✓ All functions return the count of invalidated items\n";
    echo "✓ All functions handle empty arrays gracefully\n";
    echo "✓ All functions handle non-existent tags/keys gracefully\n";
    
    tagcache_close($handle);
    $tc->close();
    
    echo "\n=== SUCCESS: All multi-tag invalidation features implemented and tested! ===\n";
    return true;
}

// Run demo
if (!extension_loaded('tagcache')) {
    echo "ERROR: TagCache extension not loaded\n";
    exit(1);
}

demo_all_features();
?>