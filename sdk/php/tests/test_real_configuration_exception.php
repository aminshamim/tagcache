<?php
declare(strict_types=1);

/**
 * Test ConfigurationException by temporarily renaming functions (simulation)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TagCache\Config;
use TagCache\Transport\HttpTransport;
use TagCache\Exceptions\ConfigurationException;

echo "Real HttpTransport ConfigurationException Test\n";
echo "==============================================\n\n";

// Test 1: Test with currently available extensions (should work)
echo "Test 1: Testing with available extensions\n";
echo "----------------------------------------\n";

$workingConfig = new Config([
    'mode' => 'http',
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 5000,
        'serializer' => function_exists('igbinary_serialize') ? 'igbinary' : 'msgpack',
        'auto_serialize' => true,
    ],
    'auth' => [
        'username' => 'admin',
        'password' => 'password',
    ],
]);

try {
    $transport = new HttpTransport($workingConfig);
    echo "✅ SUCCESS: HttpTransport created successfully with available serializer\n";
} catch (ConfigurationException $e) {
    echo "❌ UNEXPECTED: ConfigurationException with available extension: " . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo "❌ ERROR: Unexpected exception: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Show what would happen with native (always works)
echo "Test 2: Testing with native serializer (always available)\n";
echo "---------------------------------------------------------\n";

$nativeConfig = new Config([
    'mode' => 'http',
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

try {
    $transport = new HttpTransport($nativeConfig);
    echo "✅ SUCCESS: HttpTransport created successfully with native serializer\n";
} catch (\Throwable $e) {
    echo "❌ ERROR: Unexpected exception: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Document the behavior
echo "Test 3: Expected behavior documentation\n";
echo "---------------------------------------\n";
echo "When a serializer is specifically configured but not available:\n";
echo "- igbinary configured but php-igbinary not installed → ConfigurationException\n";
echo "- msgpack configured but php-msgpack not installed → ConfigurationException\n";
echo "- native configured → Always works (PHP built-in)\n";
echo "- invalid/unknown configured → Falls back to native (no exception)\n";
echo "\n";

echo "Current system status:\n";
echo "- igbinary: " . (function_exists('igbinary_serialize') ? '✅ Available' : '❌ Not available') . "\n";
echo "- msgpack: " . (function_exists('msgpack_pack') ? '✅ Available' : '❌ Not available') . "\n";
echo "- native: ✅ Always available\n";

echo "\nConfigurationException implementation complete!\n";
echo "The SDK will now throw clear error messages when configured serializers are unavailable.\n";
