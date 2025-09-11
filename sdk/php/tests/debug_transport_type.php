<?php
declare(strict_types=1);

/**
 * Debug what transport is being used
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

echo "Debug transport type:\n";
echo "====================\n\n";

// Use reflection to access the private transport property
$reflection = new \ReflectionClass($client);
$transportProperty = $reflection->getProperty('transport');
$transportProperty->setAccessible(true);
$transport = $transportProperty->getValue($client);

echo "Transport class: " . get_class($transport) . "\n";

// Check if it has our serialization properties
if (method_exists($transport, 'put') && $transport instanceof \TagCache\Transport\HttpTransport) {
    $transportReflection = new \ReflectionClass($transport);
    
    if ($transportReflection->hasProperty('serializer')) {
        $serializerProperty = $transportReflection->getProperty('serializer');
        $serializerProperty->setAccessible(true);
        echo "Serializer: " . $serializerProperty->getValue($transport) . "\n";
    }
    
    if ($transportReflection->hasProperty('autoSerialize')) {
        $autoSerializeProperty = $transportReflection->getProperty('autoSerialize');
        $autoSerializeProperty->setAccessible(true);
        echo "Auto-serialize: " . ($autoSerializeProperty->getValue($transport) ? "true" : "false") . "\n";
    }
}
