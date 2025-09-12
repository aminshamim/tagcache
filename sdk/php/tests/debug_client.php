<?php
declare(strict_types=1);

/**
 * Debug the Client get/put flow
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

echo "Debug Client put/get flow:\n";
echo "=========================\n\n";

// Test storing null
echo "1. Storing null via Client->put():\n";
$putResult = $client->put('debug_client:null', null);
echo "Put result: " . ($putResult ? "success" : "failed") . "\n\n";

// Test retrieving via Client->get()
echo "2. Retrieving via Client->get():\n";
$getValue = $client->get('debug_client:null');
echo "Client get result: " . var_export($getValue, true) . " (type: " . gettype($getValue) . ")\n\n";

// Also test the transport directly for comparison
echo "3. Transport get (raw response):\n";
$rawResponse = $client->transport->get('debug_client:null');
echo "Transport response: " . var_export($rawResponse, true) . "\n\n";

// Clean up
$client->delete('debug_client:null');
