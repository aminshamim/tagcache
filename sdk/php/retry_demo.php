<?php

require_once 'vendor/autoload.php';

use TagCache\Config;
use TagCache\Transport\HttpTransport;

echo "=== TagCache HttpTransport Retry Behavior Demo ===\n\n";

// Test 1: Authentication failure (should NOT retry)
echo "1. Testing Authentication Failure (should NOT retry):\n";
$authConfig = new Config([
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 1000,
        'max_retries' => 3,
        'retry_delay_ms' => 500,
    ],
    'auth' => [
        'username' => 'invalid',
        'password' => 'invalid',
    ],
]);

$transport = new HttpTransport($authConfig);
$startTime = microtime(true);

try {
    $transport->get('test-key');
} catch (\TagCache\Exceptions\UnauthorizedException $e) {
    $duration = microtime(true) - $startTime;
    echo "   ✓ Failed quickly in " . number_format($duration, 3) . "s (no retries)\n";
    echo "   ✓ Error: {$e->getMessage()}\n";
} catch (Exception $e) {
    echo "   ✗ Unexpected error: {$e->getMessage()}\n";
}

echo "\n";

// Test 2: Connection failure (should retry)
echo "2. Testing Connection Failure (should retry with exponential backoff):\n";
$connConfig = new Config([
    'http' => [
        'base_url' => 'http://localhost:9999', // Non-existent port
        'timeout_ms' => 300,
        'max_retries' => 2,
        'retry_delay_ms' => 200,
    ],
    'auth' => [
        'username' => 'admin',
        'password' => 'password',
    ],
]);

$transport2 = new HttpTransport($connConfig);
$startTime = microtime(true);

try {
    $transport2->get('test-key');
} catch (\TagCache\Exceptions\ConnectionException $e) {
    $duration = microtime(true) - $startTime;
    echo "   ✓ Failed after retries in " . number_format($duration, 3) . "s (with exponential backoff)\n";
    echo "   ✓ Error: {$e->getMessage()}\n";
} catch (Exception $e) {
    echo "   ✗ Unexpected error: {$e->getMessage()}\n";
}

echo "\n";

// Test 3: Successful request (should work immediately)
echo "3. Testing Successful Request (should work immediately):\n";
$successConfig = new Config([
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 5000,
        'max_retries' => 3,
    ],
    'auth' => [
        'username' => 'admin',
        'password' => 'password',
    ],
]);

$transport3 = new HttpTransport($successConfig);
$startTime = microtime(true);

try {
    $health = $transport3->health();
    $duration = microtime(true) - $startTime;
    echo "   ✓ Succeeded in " . number_format($duration, 3) . "s\n";
    echo "   ✓ Health status: {$health['status']}\n";
} catch (Exception $e) {
    echo "   ✗ Unexpected error: {$e->getMessage()}\n";
}

echo "\n=== Demo Complete ===\n";
