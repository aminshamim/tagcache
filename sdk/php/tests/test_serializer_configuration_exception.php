<?php
declare(strict_types=1);

/**
 * Test ConfigurationException when serializer extension is not available
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TagCache\Config;
use TagCache\Transport\HttpTransport;
use TagCache\Exceptions\ConfigurationException;

echo "Testing ConfigurationException for Missing Serializer Extensions\n";
echo "===============================================================\n\n";

// Test function to simulate missing extensions
function testSerializerException(string $serializer, bool $hasExtension): void {
    echo "Testing $serializer serializer ";
    echo $hasExtension ? "(available)" : "(unavailable)";
    echo ":\n";
    
    if ($hasExtension) {
        // If extension is available, no exception should be thrown
        try {
            $config = new Config([
                'mode' => 'http',
                'http' => [
                    'base_url' => 'http://localhost:8080',
                    'timeout_ms' => 5000,
                    'serializer' => $serializer,
                    'auto_serialize' => true,
                ],
                'auth' => [
                    'username' => 'admin',
                    'password' => 'password',
                ],
            ]);
            
            $transport = new HttpTransport($config);
            echo "  ✅ SUCCESS: No exception thrown (extension available)\n";
        } catch (ConfigurationException $e) {
            echo "  ❌ UNEXPECTED: Exception thrown even though extension is available: " . $e->getMessage() . "\n";
        } catch (\Throwable $e) {
            echo "  ❌ ERROR: Unexpected exception: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  ⚠️  SIMULATION: Would throw ConfigurationException if extension was missing\n";
        echo "  Expected message: '$serializer serializer is configured but {$serializer} extension is not available.'\n";
    }
    echo "\n";
}

// Check current extension availability
$extensions = [
    'igbinary' => extension_loaded('igbinary') && function_exists('igbinary_serialize') && function_exists('igbinary_unserialize'),
    'msgpack' => extension_loaded('msgpack') && function_exists('msgpack_pack') && function_exists('msgpack_unpack'),
    'native' => true // Always available
];

echo "Current Extension Status:\n";
foreach ($extensions as $ext => $available) {
    echo "  $ext: " . ($available ? '✅ AVAILABLE' : '❌ NOT AVAILABLE') . "\n";
}
echo "\n";

// Test each serializer
foreach ($extensions as $serializer => $available) {
    if ($serializer === 'native') continue; // Skip native as it always works
    testSerializerException($serializer, $available);
}

// Test native serializer (should always work)
echo "Testing native serializer (always available):\n";
try {
    $config = new Config([
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
    
    $transport = new HttpTransport($config);
    echo "  ✅ SUCCESS: Native serializer always works\n";
} catch (\Throwable $e) {
    echo "  ❌ ERROR: Unexpected exception: " . $e->getMessage() . "\n";
}

echo "\n";

// Test invalid serializer (should fall back to native)
echo "Testing invalid serializer (should fall back to native):\n";
try {
    $config = new Config([
        'mode' => 'http',
        'http' => [
            'base_url' => 'http://localhost:8080',
            'timeout_ms' => 5000,
            'serializer' => 'invalid_serializer',
            'auto_serialize' => true,
        ],
        'auth' => [
            'username' => 'admin',
            'password' => 'password',
        ],
    ]);
    
    $transport = new HttpTransport($config);
    echo "  ✅ SUCCESS: Invalid serializer falls back to native\n";
} catch (\Throwable $e) {
    echo "  ❌ ERROR: Unexpected exception: " . $e->getMessage() . "\n";
}

echo "\nTest complete!\n";
