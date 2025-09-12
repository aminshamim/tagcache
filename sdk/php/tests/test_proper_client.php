<?php
declare(strict_types=1);

/**
 * Test with proper Client usage
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

echo "Test proper Client usage:\n";
echo "========================\n\n";

$client = new Client($config);

// Test null specifically
echo "Testing null value:\n";
$client->put('test:null', null);
$retrievedValue = $client->get('test:null');
echo "Stored: null (type: " . gettype(null) . ")\n";
echo "Retrieved value: " . var_export($retrievedValue, true) . " (type: " . gettype($retrievedValue) . ")\n";
echo "Are they equal (===)? " . (null === $retrievedValue ? "YES" : "NO") . "\n\n";

// Test object specifically
echo "Testing object value:\n";
$obj = (object)['id' => 123, 'name' => 'Test Object'];
$client->put('test:object', $obj);
$retrievedValue = $client->get('test:object');
echo "Stored: " . var_export($obj, true) . " (type: " . gettype($obj) . ")\n";
echo "Retrieved value: " . var_export($retrievedValue, true) . " (type: " . gettype($retrievedValue) . ")\n";
echo "Are they equal (==)? " . ($obj == $retrievedValue ? "YES" : "NO") . "\n";
echo "Are they identical (===)? " . ($obj === $retrievedValue ? "YES" : "NO") . "\n\n";

// Test array
echo "Testing array value:\n";
$arr = ['foo', 'bar', 'baz'];
$client->put('test:array', $arr);
$retrievedValue = $client->get('test:array');
echo "Stored: " . var_export($arr, true) . " (type: " . gettype($arr) . ")\n";
echo "Retrieved value: " . var_export($retrievedValue, true) . " (type: " . gettype($retrievedValue) . ")\n";
echo "Are they equal (===)? " . ($arr === $retrievedValue ? "YES" : "NO") . "\n\n";

// Test bulk operations
echo "Testing bulk operations:\n";
$keys = ['test:null', 'test:object', 'test:array'];
$bulkResult = $client->bulkGet($keys);
echo "Bulk result structure:\n";
var_dump($bulkResult);

// Check the values in bulk result
if (isset($bulkResult['test:null'])) {
    $nullValue = is_array($bulkResult['test:null']) && array_key_exists('value', $bulkResult['test:null']) 
        ? $bulkResult['test:null']['value'] 
        : $bulkResult['test:null'];
    echo "Bulk null: " . var_export($nullValue, true) . " (type: " . gettype($nullValue) . ")\n";
    echo "Bulk null is actual null? " . (is_null($nullValue) ? "YES" : "NO") . "\n";
}

if (isset($bulkResult['test:object'])) {
    $objValue = is_array($bulkResult['test:object']) && array_key_exists('value', $bulkResult['test:object']) 
        ? $bulkResult['test:object']['value'] 
        : $bulkResult['test:object'];
    echo "Bulk object: " . var_export($objValue, true) . " (type: " . gettype($objValue) . ")\n";
}

if (isset($bulkResult['test:array'])) {
    $arrValue = is_array($bulkResult['test:array']) && array_key_exists('value', $bulkResult['test:array']) 
        ? $bulkResult['test:array']['value'] 
        : $bulkResult['test:array'];
    echo "Bulk array: " . var_export($arrValue, true) . " (type: " . gettype($arrValue) . ")\n";
}

// Cleanup
$client->delete('test:null');
$client->delete('test:object');
$client->delete('test:array');
