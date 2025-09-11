<?php

require_once 'vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

try {
    $config = new Config([
        'mode' => 'http',
        'http' => [
            'base_url' => 'http://localhost:8080',
            'timeout_ms' => 5000,
        ],
        'auth' => [
            'username' => 'admin',
            'password' => 'password',
        ],
    ]);
    
    $client = new Client($config);
    
    echo "Testing health check...\n";
    $health = $client->health();
    var_dump($health);
    
    echo "\nTesting PUT operation...\n";
    $key = 'debug:test:' . uniqid();
    $value = 'debug-value-' . time();
    $result = $client->put($key, $value, 300, ['debug']);
    var_dump($result);
    
    if ($result) {
        echo "\nTesting GET operation...\n";
        $retrieved = $client->get($key);
        var_dump($retrieved);
        
        echo "\nTesting DELETE operation...\n";
        $deleted = $client->delete($key);
        var_dump($deleted);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
