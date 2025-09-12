<?php
declare(strict_types=1);

/**
 * Detailed debug integration test
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

$config = new Config([
    'mode' => 'http', // Force HTTP transport
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

echo "Detailed Debug Test\n";
echo "==================\n\n";

$client = new Client($config);

// Test null specifically
echo "Testing null value:\n";
$client->put('debug_test:null', null);
$retrievedValue = $client->get('debug_test:null');
echo "Stored: null (type: " . gettype(null) . ")\n";
echo "Retrieved value: " . var_export($retrievedValue, true) . " (type: " . gettype($retrievedValue) . ")\n";
echo "Are they equal (===)? " . (null === $retrievedValue ? "YES" : "NO") . "\n\n";

// Test object specifically
echo "Testing object value:\n";
$obj = (object)['id' => 123, 'name' => 'Test Object'];
$client->put('debug_test:object', $obj);
$retrievedValue = $client->get('debug_test:object');
echo "Stored: " . var_export($obj, true) . " (type: " . gettype($obj) . ")\n";
echo "Retrieved value: " . var_export($retrievedValue, true) . " (type: " . gettype($retrievedValue) . ")\n";
echo "Are they equal (==)? " . ($obj == $retrievedValue ? "YES" : "NO") . "\n";
echo "Are they identical (===)? " . ($obj === $retrievedValue ? "YES" : "NO") . "\n\n";

// Test bulk operations
echo "Testing bulk operations:\n";
$keys = ['debug_test:null', 'debug_test:object'];
$bulkResult = $client->bulkGet($keys);
echo "Bulk result:\n";
var_dump($bulkResult);

// Cleanup
$client->delete('debug_test:null');
$client->delete('debug_test:object');
