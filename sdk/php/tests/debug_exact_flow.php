<?php
declare(strict_types=1);

/**
 * Debug the exact Client flow step by step
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

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

$client = new Client($config);

echo "Debug exact Client flow:\n";
echo "=======================\n\n";

// Use reflection to access the private transport property
$reflection = new \ReflectionClass($client);
$transportProperty = $reflection->getProperty('transport');
$transportProperty->setAccessible(true);
$transport = $transportProperty->getValue($client);

echo "1. Client->put() null value:\n";
$client->put('debug_exact:null', null);

echo "2. Raw transport->get() response:\n";
$rawTransportResponse = $transport->get('debug_exact:null');
echo "Transport response: " . var_export($rawTransportResponse, true) . "\n\n";

echo "3. Client->get() response:\n";
$clientResponse = $client->get('debug_exact:null');
echo "Client response: " . var_export($clientResponse, true) . " (type: " . gettype($clientResponse) . ")\n\n";

echo "4. Let's also test with an object:\n";
$obj = (object)['id' => 123, 'name' => 'test'];
$client->put('debug_exact:object', $obj);

$rawObjResponse = $transport->get('debug_exact:object');
echo "Object transport response: " . var_export($rawObjResponse, true) . "\n\n";

$clientObjResponse = $client->get('debug_exact:object');
echo "Object client response: " . var_export($clientObjResponse, true) . " (type: " . gettype($clientObjResponse) . ")\n\n";

// Cleanup
$client->delete('debug_exact:null');
$client->delete('debug_exact:object');
