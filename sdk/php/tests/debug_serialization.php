<?php
declare(strict_types=1);

/**
 * Debug test to see what the server actually stores and retrieves
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;
use TagCache\Transport\HttpTransport;

$config = new Config([
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 5000,
        'serializer' => 'native',
        'auto_serialize' => true,
    ],
    'auth' => [
        'username' => 'admin',
        'password' => 'password',
    ],
]);

echo "Debug: Testing null value serialization\n";
echo "=====================================\n\n";

$transport = new HttpTransport($config);

// Test storing null
echo "1. Storing null value...\n";
$putResult = $transport->put('debug:null', null, 60000, []);
echo "Put result: " . ($putResult ? "success" : "failed") . "\n\n";

// Test retrieving null
echo "2. Retrieving null value...\n";
try {
    $getResult = $transport->get('debug:null');
    echo "Raw response:\n";
    var_dump($getResult);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n3. Testing object serialization...\n";
$obj = (object)['id' => 123, 'name' => 'test'];
$putResult = $transport->put('debug:object', $obj, 60000, []);
echo "Put object result: " . ($putResult ? "success" : "failed") . "\n";

try {
    $getResult = $transport->get('debug:object');
    echo "Object raw response:\n";
    var_dump($getResult);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Cleanup
$transport->delete('debug:null');
$transport->delete('debug:object');
