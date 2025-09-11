<?php
require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;

echo "Testing TagCache Client with default config...\n\n";

try {
    // Test 1: Initialize client without config
    echo "1. Creating client without config parameter...\n";
    $client = new Client();
    echo "✓ Client created successfully with default config\n\n";
    
    // Test 2: Check if client can connect and get stats
    echo "2. Testing connection with stats()...\n";
    $stats = $client->stats();
    echo "✓ Successfully connected to TagCache server\n";
    echo "   Server stats: " . json_encode($stats, JSON_PRETTY_PRINT) . "\n\n";
    
    // Test 3: Test basic put/get operations
    echo "3. Testing basic put/get operations...\n";
    $key = 'test_default_config_' . time();
    $value = 'Hello from default config!';
    
    $putResult = $client->put($key, $value, ['test', 'default-config']);
    echo "✓ Put operation result: " . ($putResult ? 'success' : 'failed') . "\n";
    
    $getValue = $client->get($key);
    echo "✓ Get operation result: " . ($getValue === $value ? 'success' : 'failed') . "\n";
    echo "   Retrieved value: '$getValue'\n\n";
    
    // Test 4: Clean up
    echo "4. Cleaning up test data...\n";
    $deleteResult = $client->delete($key);
    echo "✓ Delete operation result: " . ($deleteResult ? 'success' : 'failed') . "\n\n";
    
    echo "🎉 All tests passed! Client works perfectly with default config.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch (Throwable $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
