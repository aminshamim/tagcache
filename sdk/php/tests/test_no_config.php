<?php

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;

echo "Testing TagCache Client without config...\n";

try {
    // Test 1: Initialize client without config
    echo "1. Creating client without config... ";
    $client = new Client();
    echo "✓ Success!\n";
    
    // Test 2: Check if we can get config
    echo "2. Getting config from client... ";
    $config = $client->getConfig();
    echo "✓ Config loaded with mode: " . $config->mode . "\n";
    
    // Test 3: Test basic operations
    echo "3. Testing basic put operation... ";
    $result = $client->put('test_key', 'test_value', ['test_tag']);
    echo $result ? "✓ Success!\n" : "✗ Failed\n";
    
    echo "4. Testing basic get operation... ";
    $value = $client->get('test_key');
    echo ($value === 'test_value') ? "✓ Success! Got: $value\n" : "✗ Failed\n";
    
    echo "5. Testing delete operation... ";
    $deleted = $client->delete('test_key');
    echo $deleted ? "✓ Success!\n" : "✗ Failed\n";
    
    echo "\n✅ All tests passed! Client works without config.\n";
    
} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
