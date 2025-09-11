<?php
declare(strict_types=1);

/**
 * Debug the full put/get flow
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Transport\HttpTransport;
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

$transport = new HttpTransport($config);

echo "Debug full put/get flow:\n";
echo "=======================\n\n";

// Test storing null
echo "1. Storing null via transport->put():\n";
$transport->put('debug_flow:null', null);

// Test retrieving via transport->get() (raw response)
echo "2. Retrieving via transport->get() (raw response):\n";
$rawResponse = $transport->get('debug_flow:null');
echo "Raw response: " . var_export($rawResponse, true) . "\n\n";

// Clean up
$transport->delete('debug_flow:null');
