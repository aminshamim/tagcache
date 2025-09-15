<?php

// Quick timeout reproduction test
echo "=== Timeout Issue Reproduction ===\n";

$client = tagcache_create([
    'mode' => 'tcp',
    'host' => '127.0.0.1', 
    'port' => 1984,
    'timeout_ms' => 1000, // Very short timeout
]);

echo "Testing with 1000ms timeout...\n";

for ($i = 0; $i < 10; $i++) {
    $start = microtime(true);
    
    $key = "test_key_$i";
    $value = str_repeat("x", 1000); // 1KB data
    $tags = ['test', 'timeout', "batch_$i"];
    
    $result = tagcache_put($client, $key, $value, $tags, 5000);
    
    $duration = (microtime(true) - $start) * 1000;
    
    if ($result) {
        printf("✓ Put #%d: %.2fms\n", $i, $duration);
    } else {
        printf("✗ Put #%d FAILED: %.2fms\n", $i, $duration);
    }
    
    if ($duration > 800) { // Close to timeout
        echo "WARNING: Operation took longer than expected!\n";
        break;
    }
}

tagcache_close($client);

?>