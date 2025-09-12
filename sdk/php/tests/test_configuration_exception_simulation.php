<?php
declare(strict_types=1);

/**
 * Test ConfigurationException by simulating missing extensions
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TagCache\Config;
use TagCache\Exceptions\ConfigurationException;

echo "Testing ConfigurationException with Simulated Missing Extensions\n";
echo "===============================================================\n\n";

// Create a test class that simulates the validateSerializer logic
class SerializerValidator {
    public function validateSerializer(string $preferred, array $availableExtensions = []): string
    {
        $hasIgbinary = in_array('igbinary', $availableExtensions);
        $hasMsgpack = in_array('msgpack', $availableExtensions);
        
        return match($preferred) {
            'igbinary' => $hasIgbinary ? 'igbinary' : 
                         throw new ConfigurationException("igbinary serializer is configured but igbinary extension is not available. Please install php-igbinary extension or change the serializer configuration."),
            'msgpack' => $hasMsgpack ? 'msgpack' : 
                        throw new ConfigurationException("msgpack serializer is configured but msgpack extension is not available. Please install php-msgpack extension or change the serializer configuration."),
            'native' => 'native',
            default => 'native'
        };
    }
}

$validator = new SerializerValidator();

// Test scenarios
$scenarios = [
    ['serializer' => 'igbinary', 'extensions' => [], 'shouldThrow' => true],
    ['serializer' => 'igbinary', 'extensions' => ['igbinary'], 'shouldThrow' => false],
    ['serializer' => 'msgpack', 'extensions' => [], 'shouldThrow' => true],
    ['serializer' => 'msgpack', 'extensions' => ['msgpack'], 'shouldThrow' => false],
    ['serializer' => 'native', 'extensions' => [], 'shouldThrow' => false],
    ['serializer' => 'invalid', 'extensions' => [], 'shouldThrow' => false], // Falls back to native
];

foreach ($scenarios as $scenario) {
    $serializer = $scenario['serializer'];
    $extensions = $scenario['extensions'];
    $shouldThrow = $scenario['shouldThrow'];
    
    echo "Testing: $serializer with extensions [" . implode(', ', $extensions) . "]\n";
    
    try {
        $result = $validator->validateSerializer($serializer, $extensions);
        
        if ($shouldThrow) {
            echo "  ❌ FAIL: Expected ConfigurationException but got result: $result\n";
        } else {
            echo "  ✅ PASS: Got expected result: $result\n";
        }
        
    } catch (ConfigurationException $e) {
        if ($shouldThrow) {
            echo "  ✅ PASS: Got expected ConfigurationException: " . $e->getMessage() . "\n";
        } else {
            echo "  ❌ FAIL: Unexpected ConfigurationException: " . $e->getMessage() . "\n";
        }
    } catch (\Throwable $e) {
        echo "  ❌ ERROR: Unexpected exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "Test complete!\n";
