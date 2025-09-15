<?php
ini_set('extension', '/Users/arogga/project/tagcache/php-ext/modules/tagcache.so');

echo "=== Additional PHP TagCache Multi-Tag Tests ===\n\n";

function test_detailed_scenarios() {
    echo "--- Testing Detailed Scenarios ---\n";
    
    $tc = TagCache::create(['host' => '127.0.0.1', 'port' => 1984]);
    if (!$tc) {
        echo "ERROR: Failed to create connection\n";
        return false;
    }
    
    // Clear any existing data
    $tc->flush();
    
    // Test scenario 1: Complex tag combinations
    echo "Setting up complex test data...\n";
    $tc->set('item1', 'data1', ['users', 'active'], 60000);
    $tc->set('item2', 'data2', ['users', 'inactive'], 60000);
    $tc->set('item3', 'data3', ['admins', 'active'], 60000);
    $tc->set('item4', 'data4', ['admins', 'inactive'], 60000);
    $tc->set('item5', 'data5', ['users', 'admins', 'active'], 60000);
    
    echo "\nInitial state:\n";
    for ($i = 1; $i <= 5; $i++) {
        $val = $tc->get("item$i");
        echo "item$i: " . ($val ? "EXISTS" : "NULL") . "\n";
    }
    
    // Test 1: Invalidate ANY of multiple tags
    echo "\nTest 1: Invalidate ANY ['users', 'admins']\n";
    $count = $tc->invalidateTagsAny(['users', 'admins']);
    echo "Invalidated: $count items\n";
    
    for ($i = 1; $i <= 5; $i++) {
        $val = $tc->get("item$i");
        echo "item$i: " . ($val ? "EXISTS" : "NULL") . "\n";
    }
    
    // Reset data
    $tc->set('item1', 'data1', ['users', 'active'], 60000);
    $tc->set('item2', 'data2', ['users', 'inactive'], 60000);
    $tc->set('item3', 'data3', ['admins', 'active'], 60000);
    $tc->set('item4', 'data4', ['admins', 'inactive'], 60000);
    $tc->set('item5', 'data5', ['users', 'admins', 'active'], 60000);
    
    // Test 2: Invalidate ALL of multiple tags
    echo "\nTest 2: Invalidate ALL ['users', 'active']\n";
    $count = $tc->invalidateTagsAll(['users', 'active']);
    echo "Invalidated: $count items\n";
    
    for ($i = 1; $i <= 5; $i++) {
        $val = $tc->get("item$i");
        echo "item$i: " . ($val ? "EXISTS" : "NULL") . "\n";
    }
    
    // Test 3: Test protocol commands
    echo "\nTest 3: Test direct protocol validation\n";
    
    // Reset with simple data
    $tc->flush();
    $tc->set('simple1', 'value1', ['tag_a'], 60000);
    $tc->set('simple2', 'value2', ['tag_b'], 60000);
    $tc->set('simple3', 'value3', ['tag_a', 'tag_b'], 60000);
    
    echo "Initial simple data:\n";
    echo "simple1: " . ($tc->get('simple1') ? "EXISTS" : "NULL") . "\n";
    echo "simple2: " . ($tc->get('simple2') ? "EXISTS" : "NULL") . "\n";
    echo "simple3: " . ($tc->get('simple3') ? "EXISTS" : "NULL") . "\n";
    
    echo "\nInvalidating ANY ['tag_a']:\n";
    $count = $tc->invalidateTagsAny(['tag_a']);
    echo "Invalidated: $count items\n";
    echo "simple1: " . ($tc->get('simple1') ? "EXISTS" : "NULL") . " (should be NULL)\n";
    echo "simple2: " . ($tc->get('simple2') ? "EXISTS" : "NULL") . " (should be EXISTS)\n";
    echo "simple3: " . ($tc->get('simple3') ? "EXISTS" : "NULL") . " (should be NULL)\n";
    
    $tc->close();
    echo "\nDetailed scenarios test completed!\n\n";
    return true;
}

// Test single tag vs multi-tag invalidation
function test_comparison() {
    echo "--- Testing Single vs Multi-Tag Invalidation ---\n";
    
    $tc = TagCache::create(['host' => '127.0.0.1', 'port' => 1984]);
    if (!$tc) {
        echo "ERROR: Failed to create connection\n";
        return false;
    }
    
    $tc->flush();
    
    // Set up comparison data
    $tc->set('comp1', 'value1', ['alpha'], 60000);
    $tc->set('comp2', 'value2', ['beta'], 60000);
    $tc->set('comp3', 'value3', ['alpha', 'beta'], 60000);
    $tc->set('comp4', 'value4', ['gamma'], 60000);
    
    echo "Test data set up:\n";
    echo "comp1 (alpha): " . ($tc->get('comp1') ? "EXISTS" : "NULL") . "\n";
    echo "comp2 (beta): " . ($tc->get('comp2') ? "EXISTS" : "NULL") . "\n";
    echo "comp3 (alpha,beta): " . ($tc->get('comp3') ? "EXISTS" : "NULL") . "\n";
    echo "comp4 (gamma): " . ($tc->get('comp4') ? "EXISTS" : "NULL") . "\n";
    
    // Test single tag invalidation
    echo "\nSingle tag invalidation ('alpha'):\n";
    $count = $tc->invalidateTag('alpha');
    echo "Invalidated: $count items\n";
    echo "comp1: " . ($tc->get('comp1') ? "EXISTS" : "NULL") . "\n";
    echo "comp2: " . ($tc->get('comp2') ? "EXISTS" : "NULL") . "\n";
    echo "comp3: " . ($tc->get('comp3') ? "EXISTS" : "NULL") . "\n";
    echo "comp4: " . ($tc->get('comp4') ? "EXISTS" : "NULL") . "\n";
    
    $tc->close();
    echo "\nComparison test completed!\n\n";
    return true;
}

// Run tests
if (!extension_loaded('tagcache')) {
    echo "ERROR: TagCache extension not loaded\n";
    exit(1);
}

test_detailed_scenarios();
test_comparison();

echo "=== Additional tests completed! ===\n";
?>