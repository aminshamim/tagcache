<?php

// Debug timeout issue with PHP extension
error_reporting(E_ALL);

echo "=== TagCache PHP Extension Timeout Debug ===\n";

// Test with different timeout values
$timeout_configs = [
    ['timeout_ms' => 1000, 'desc' => '1 second timeout'],
    ['timeout_ms' => 5000, 'desc' => '5 second timeout (default)'], 
    ['timeout_ms' => 10000, 'desc' => '10 second timeout'],
];

foreach ($timeout_configs as $config) {
    echo "\n--- Testing {$config['desc']} ---\n";
    
    try {
        $client = tagcache_create([
            'mode' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 1984,
            'timeout_ms' => $config['timeout_ms'],
            'connect_timeout_ms' => 3000,
        ]);

        if (!$client) {
            echo "Failed to create client\n";
            continue;
        }

        echo "Client created successfully\n";
        
        // Test simple set operation
        $start_time = microtime(true);
        echo "Attempting set operation...\n";
        
        $result = tagcache_put($client, 'test_timeout_key', 'test_value', ['test'], 60000);
        
        $end_time = microtime(true);
        $duration = ($end_time - $start_time) * 1000;
        
        if ($result) {
            printf("✓ SET successful in %.2fms\n", $duration);
            
            // Test get operation
            $start_time = microtime(true);
            $value = tagcache_get($client, 'test_timeout_key');
            $end_time = microtime(true);
            $get_duration = ($end_time - $start_time) * 1000;
            
            if ($value !== null) {
                printf("✓ GET successful in %.2fms, value: %s\n", $get_duration, $value);
            } else {
                printf("✗ GET failed in %.2fms\n", $get_duration);
            }
        } else {
            printf("✗ SET failed in %.2fms\n", $duration);
        }
        
        tagcache_close($client);
        
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Server Connectivity Test ===\n";

// Test if server is responding
$sock = @fsockopen('127.0.0.1', 1984, $errno, $errstr, 5);
if ($sock) {
    echo "✓ Server is listening on port 1984\n";
    
    // Test simple TCP command
    fwrite($sock, "STATS\n");
    $response = fgets($sock);
    echo "Server response: $response";
    fclose($sock);
} else {
    echo "✗ Cannot connect to server: $errstr ($errno)\n";
    echo "Make sure TagCache server is running:\n";
    echo "  tagcache server\n";
}

echo "\n=== System Information ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Extension loaded: " . (extension_loaded('tagcache') ? 'Yes' : 'No') . "\n";
echo "OS: " . php_uname('s') . " " . php_uname('r') . "\n";

?>