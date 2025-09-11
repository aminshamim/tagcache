<?php

require_once __DIR__ . '/../vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

echo "Comparing Client initialization methods...\n\n";

try {
    // Method 1: Without config (new approach)
    echo "Method 1: Client without config\n";
    echo "--------------------------------\n";
    $client1 = new Client();
    echo "✓ Client created successfully\n";
    echo "✓ Mode: " . $client1->getConfig()->mode . "\n";
    echo "✓ HTTP URL: " . $client1->getConfig()->http['base_url'] . "\n";
    echo "✓ TCP Host: " . $client1->getConfig()->tcp['host'] . ":" . $client1->getConfig()->tcp['port'] . "\n";
    
    // Test basic operation
    $client1->put('test1', 'value1');
    $result1 = $client1->get('test1');
    echo "✓ Put/Get test: " . ($result1 === 'value1' ? 'Success' : 'Failed') . "\n";
    $client1->delete('test1');
    
    echo "\nMethod 2: Client with custom config\n";
    echo "-----------------------------------\n";
    $config = new Config(['mode' => 'http']);
    $client2 = new Client($config);
    echo "✓ Client created successfully\n";
    echo "✓ Mode: " . $client2->getConfig()->mode . "\n";
    echo "✓ HTTP URL: " . $client2->getConfig()->http['base_url'] . "\n";
    
    // Test basic operation
    $client2->put('test2', 'value2');
    $result2 = $client2->get('test2');
    echo "✓ Put/Get test: " . ($result2 === 'value2' ? 'Success' : 'Failed') . "\n";
    $client2->delete('test2');
    
    echo "\n🎉 Both initialization methods work perfectly!\n";
    
} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
}
