<?php
ini_set('extension', '/Users/arogga/project/tagcache/php-ext/modules/tagcache.so');

echo "=== PHP TagCache Multi-Tag Invalidation Test ===\n\n";

// Test both procedural and OO API
function test_procedural() {
    echo "--- Testing Procedural API ---\n";
    
    // Create connection
    $handle = tagcache_create('127.0.0.1', 1984, 'admin', 'password');
    if (!$handle) {
        echo "ERROR: Failed to create connection\n";
        return false;
    }
    
    // Set up test data
    echo "Setting up test data...\n";
    tagcache_put($handle, 'key1', 'value1', ['tag1', 'tag2'], 60000);
    tagcache_put($handle, 'key2', 'value2', ['tag2', 'tag3'], 60000);
    tagcache_put($handle, 'key3', 'value3', ['tag1', 'tag3'], 60000);
    tagcache_put($handle, 'key4', 'value4', ['tag4'], 60000);
    tagcache_put($handle, 'key5', 'value5', ['tag1', 'tag2', 'tag3'], 60000);
    
    // Test 1: invalidate_tags_any - should invalidate keys with ANY of the specified tags
    echo "\nTest 1: tagcache_invalidate_tags_any(['tag1', 'tag2'])\n";
    $count = tagcache_invalidate_tags_any($handle, ['tag1', 'tag2']);
    echo "Invalidated count: $count\n";
    
    // Check what remains
    $val1 = tagcache_get($handle, 'key1'); // Should be null (had tag1, tag2)
    $val2 = tagcache_get($handle, 'key2'); // Should be null (had tag2)
    $val3 = tagcache_get($handle, 'key3'); // Should be null (had tag1)
    $val4 = tagcache_get($handle, 'key4'); // Should exist (had tag4 only)
    $val5 = tagcache_get($handle, 'key5'); // Should be null (had tag1, tag2)
    
    echo "key1: " . ($val1 ? "EXISTS" : "NULL") . "\n";
    echo "key2: " . ($val2 ? "EXISTS" : "NULL") . "\n";
    echo "key3: " . ($val3 ? "EXISTS" : "NULL") . "\n";
    echo "key4: " . ($val4 ? "EXISTS" : "NULL") . "\n";
    echo "key5: " . ($val5 ? "EXISTS" : "NULL") . "\n";
    
    // Reset data for next test
    tagcache_put($handle, 'key1', 'value1', ['tag1', 'tag2'], 60000);
    tagcache_put($handle, 'key2', 'value2', ['tag2', 'tag3'], 60000);
    tagcache_put($handle, 'key3', 'value3', ['tag1', 'tag3'], 60000);
    tagcache_put($handle, 'key5', 'value5', ['tag1', 'tag2', 'tag3'], 60000);
    
    // Test 2: invalidate_tags_all - should invalidate keys with ALL of the specified tags
    echo "\nTest 2: tagcache_invalidate_tags_all(['tag1', 'tag2'])\n";
    $count = tagcache_invalidate_tags_all($handle, ['tag1', 'tag2']);
    echo "Invalidated count: $count\n";
    
    // Check what remains
    $val1 = tagcache_get($handle, 'key1'); // Should be null (had both tag1 and tag2)
    $val2 = tagcache_get($handle, 'key2'); // Should exist (had tag2 but not tag1)
    $val3 = tagcache_get($handle, 'key3'); // Should exist (had tag1 but not tag2)
    $val4 = tagcache_get($handle, 'key4'); // Should exist (had neither)
    $val5 = tagcache_get($handle, 'key5'); // Should be null (had both tag1 and tag2)
    
    echo "key1: " . ($val1 ? "EXISTS" : "NULL") . "\n";
    echo "key2: " . ($val2 ? "EXISTS" : "NULL") . "\n";
    echo "key3: " . ($val3 ? "EXISTS" : "NULL") . "\n";
    echo "key4: " . ($val4 ? "EXISTS" : "NULL") . "\n";
    echo "key5: " . ($val5 ? "EXISTS" : "NULL") . "\n";
    
    // Reset data for next test
    tagcache_put($handle, 'key1', 'value1', ['tag1'], 60000);
    tagcache_put($handle, 'key6', 'value6', ['tag5'], 60000);
    tagcache_put($handle, 'key7', 'value7', ['tag6'], 60000);
    
    // Test 3: invalidate_keys - should invalidate specific keys
    echo "\nTest 3: tagcache_invalidate_keys(['key1', 'key6'])\n";
    $count = tagcache_invalidate_keys($handle, ['key1', 'key6']);
    echo "Invalidated count: $count\n";
    
    // Check what remains
    $val1 = tagcache_get($handle, 'key1'); // Should be null
    $val2 = tagcache_get($handle, 'key2'); // Should exist
    $val6 = tagcache_get($handle, 'key6'); // Should be null
    $val7 = tagcache_get($handle, 'key7'); // Should exist
    
    echo "key1: " . ($val1 ? "EXISTS" : "NULL") . "\n";
    echo "key2: " . ($val2 ? "EXISTS" : "NULL") . "\n";
    echo "key6: " . ($val6 ? "EXISTS" : "NULL") . "\n";
    echo "key7: " . ($val7 ? "EXISTS" : "NULL") . "\n";
    
    tagcache_close($handle);
    echo "Procedural API tests completed!\n\n";
    return true;
}

function test_oo() {
    echo "--- Testing OO API ---\n";
    
    // Create connection
    $tc = TagCache::create('127.0.0.1', 1984, 'admin', 'password');
    if (!$tc) {
        echo "ERROR: Failed to create connection\n";
        return false;
    }
    
    // Set up test data
    echo "Setting up test data...\n";
    $tc->set('oo_key1', 'value1', ['oo_tag1', 'oo_tag2'], 60000);
    $tc->set('oo_key2', 'value2', ['oo_tag2', 'oo_tag3'], 60000);
    $tc->set('oo_key3', 'value3', ['oo_tag1', 'oo_tag3'], 60000);
    $tc->set('oo_key4', 'value4', ['oo_tag4'], 60000);
    $tc->set('oo_key5', 'value5', ['oo_tag1', 'oo_tag2', 'oo_tag3'], 60000);
    
    // Test 1: invalidateTagsAny
    echo "\nTest 1: \$tc->invalidateTagsAny(['oo_tag1', 'oo_tag2'])\n";
    $count = $tc->invalidateTagsAny(['oo_tag1', 'oo_tag2']);
    echo "Invalidated count: $count\n";
    
    // Check what remains
    $val1 = $tc->get('oo_key1'); // Should be null
    $val2 = $tc->get('oo_key2'); // Should be null
    $val3 = $tc->get('oo_key3'); // Should be null
    $val4 = $tc->get('oo_key4'); // Should exist
    $val5 = $tc->get('oo_key5'); // Should be null
    
    echo "oo_key1: " . ($val1 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key2: " . ($val2 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key3: " . ($val3 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key4: " . ($val4 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key5: " . ($val5 ? "EXISTS" : "NULL") . "\n";
    
    // Reset data for next test
    $tc->set('oo_key1', 'value1', ['oo_tag1', 'oo_tag2'], 60000);
    $tc->set('oo_key2', 'value2', ['oo_tag2', 'oo_tag3'], 60000);
    $tc->set('oo_key3', 'value3', ['oo_tag1', 'oo_tag3'], 60000);
    $tc->set('oo_key5', 'value5', ['oo_tag1', 'oo_tag2', 'oo_tag3'], 60000);
    
    // Test 2: invalidateTagsAll
    echo "\nTest 2: \$tc->invalidateTagsAll(['oo_tag1', 'oo_tag2'])\n";
    $count = $tc->invalidateTagsAll(['oo_tag1', 'oo_tag2']);
    echo "Invalidated count: $count\n";
    
    // Check what remains
    $val1 = $tc->get('oo_key1'); // Should be null (had both)
    $val2 = $tc->get('oo_key2'); // Should exist (missing oo_tag1)
    $val3 = $tc->get('oo_key3'); // Should exist (missing oo_tag2)
    $val4 = $tc->get('oo_key4'); // Should exist
    $val5 = $tc->get('oo_key5'); // Should be null (had both)
    
    echo "oo_key1: " . ($val1 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key2: " . ($val2 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key3: " . ($val3 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key4: " . ($val4 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key5: " . ($val5 ? "EXISTS" : "NULL") . "\n";
    
    // Reset data for next test
    $tc->set('oo_key1', 'value1', ['oo_tag1'], 60000);
    $tc->set('oo_key6', 'value6', ['oo_tag5'], 60000);
    $tc->set('oo_key7', 'value7', ['oo_tag6'], 60000);
    
    // Test 3: invalidateKeys
    echo "\nTest 3: \$tc->invalidateKeys(['oo_key1', 'oo_key6'])\n";
    $count = $tc->invalidateKeys(['oo_key1', 'oo_key6']);
    echo "Invalidated count: $count\n";
    
    // Check what remains
    $val1 = $tc->get('oo_key1'); // Should be null
    $val2 = $tc->get('oo_key2'); // Should exist
    $val6 = $tc->get('oo_key6'); // Should be null
    $val7 = $tc->get('oo_key7'); // Should exist
    
    echo "oo_key1: " . ($val1 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key2: " . ($val2 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key6: " . ($val6 ? "EXISTS" : "NULL") . "\n";
    echo "oo_key7: " . ($val7 ? "EXISTS" : "NULL") . "\n";
    
    $tc->close();
    echo "OO API tests completed!\n\n";
    return true;
}

function test_edge_cases() {
    echo "--- Testing Edge Cases ---\n";
    
    $handle = tagcache_create('127.0.0.1', 1984, 'admin', 'password');
    if (!$handle) {
        echo "ERROR: Failed to create connection\n";
        return false;
    }
    
    // Test empty arrays
    echo "Test 1: Empty tag array\n";
    $count = tagcache_invalidate_tags_any($handle, []);
    echo "Empty tags_any count: $count\n";
    
    $count = tagcache_invalidate_tags_all($handle, []);
    echo "Empty tags_all count: $count\n";
    
    $count = tagcache_invalidate_keys($handle, []);
    echo "Empty keys count: $count\n";
    
    // Test non-existent tags/keys
    echo "\nTest 2: Non-existent tags/keys\n";
    $count = tagcache_invalidate_tags_any($handle, ['nonexistent_tag']);
    echo "Non-existent tag count: $count\n";
    
    $count = tagcache_invalidate_keys($handle, ['nonexistent_key']);
    echo "Non-existent key count: $count\n";
    
    tagcache_close($handle);
    echo "Edge case tests completed!\n\n";
    return true;
}

// Run tests
if (!extension_loaded('tagcache')) {
    echo "ERROR: TagCache extension not loaded\n";
    exit(1);
}

echo "Extension loaded successfully!\n\n";

test_procedural();
test_oo();
test_edge_cases();

echo "=== All tests completed! ===\n";
?>