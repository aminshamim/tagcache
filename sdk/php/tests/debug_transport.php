<?php

require_once 'vendor/autoload.php';

use TagCache\Config;
use TagCache\Transport\HttpTransport;

try {
    $config = new Config([
        'http' => [
            'base_url' => 'http://localhost:8080',
            'timeout_ms' => 5000,
        ],
        'auth' => [
            'username' => 'admin',
            'password' => 'password',
        ],
    ]);
    
    $transport = new HttpTransport($config);
    
    echo "Testing transport PUT directly...\n";
    $key = 'direct:test:' . uniqid();
    $value = 'direct-value-' . time();
    $result = $transport->put($key, $value, 30000, ['direct']);
    var_dump($result);
    
    if ($result) {
        echo "\nTesting transport GET directly...\n";
        $retrieved = $transport->get($key);
        var_dump($retrieved);
        
        echo "\nTesting transport DELETE directly...\n";
        $deleted = $transport->delete($key);
        var_dump($deleted);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
