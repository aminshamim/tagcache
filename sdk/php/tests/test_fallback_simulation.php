<?php
declare(strict_types=1);

/**
 * Test fallback behavior when extensions are not available
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Config;
use TagCache\Transport\HttpTransport;

echo "Testing Fallback When Extensions Are Not Available\n";
echo "=================================================\n\n";

// Create a custom HttpTransport class for testing that simulates missing extensions
class TestHttpTransport extends HttpTransport {
    private bool $simulateNoIgbinary;
    private bool $simulateNoMsgpack;
    
    public function __construct(Config $config, bool $simulateNoIgbinary = false, bool $simulateNoMsgpack = false) {
        $this->simulateNoIgbinary = $simulateNoIgbinary;
        $this->simulateNoMsgpack = $simulateNoMsgpack;
        parent::__construct($config);
    }
    
    // Override the validateSerializer method to simulate missing extensions
    protected function validateSerializer(string $preferred): string
    {
        return match($preferred) {
            'igbinary' => (!$this->simulateNoIgbinary && function_exists('igbinary_serialize') && function_exists('igbinary_unserialize')) ? 'igbinary' : 
                         ((!$this->simulateNoMsgpack && function_exists('msgpack_pack') && function_exists('msgpack_unpack')) ? 'msgpack' : 'native'),
            'msgpack' => (!$this->simulateNoMsgpack && function_exists('msgpack_pack') && function_exists('msgpack_unpack')) ? 'msgpack' : 'native',
            'native' => 'native',
            default => 'native'
        };
    }
    
    // Override serialization methods to simulate missing extensions
    protected function performSerialization(mixed $value): string
    {
        $serializer = $this->getSerializer();
        return match($serializer) {
            'igbinary' => (!$this->simulateNoIgbinary && function_exists('igbinary_serialize')) ? igbinary_serialize($value) : serialize($value),
            'msgpack' => (!$this->simulateNoMsgpack && function_exists('msgpack_pack')) ? msgpack_pack($value) : serialize($value),
            'native' => serialize($value),
            default => serialize($value)
        };
    }
    
    protected function performDeserialization(string $data): mixed
    {
        $serializer = $this->getSerializer();
        return match($serializer) {
            'igbinary' => (!$this->simulateNoIgbinary && function_exists('igbinary_unserialize')) ? igbinary_unserialize($data) : unserialize($data),
            'msgpack' => (!$this->simulateNoMsgpack && function_exists('msgpack_unpack')) ? msgpack_unpack($data) : unserialize($data),
            'native' => unserialize($data),
            default => unserialize($data)
        };
    }
    
    public function getSerializer(): string {
        $reflection = new \ReflectionClass(parent::class);
        $property = $reflection->getProperty('serializer');
        $property->setAccessible(true);
        return $property->getValue($this);
    }
}

$config = new Config([
    'mode' => 'http',
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 5000,
        'serializer' => 'igbinary',
        'auto_serialize' => true,
    ],
    'auth' => [
        'username' => 'admin',
        'password' => 'password',
    ],
]);

// Test scenarios
$scenarios = [
    'All available' => [false, false],
    'No igbinary' => [true, false],
    'No msgpack' => [false, true], 
    'No igbinary, no msgpack' => [true, true],
];

foreach ($scenarios as $scenarioName => [$noIgbinary, $noMsgpack]) {
    echo "Scenario: $scenarioName\n";
    echo str_repeat('-', strlen($scenarioName) + 10) . "\n";
    
    $transport = new TestHttpTransport($config, $noIgbinary, $noMsgpack);
    $actualSerializer = $transport->getSerializer();
    
    echo "  Requested: igbinary\n";
    echo "  Actual: $actualSerializer\n";
    
    // Test serialization
    $testData = ['test' => 'data', 'nested' => ['array' => [1, 2, 3]]];
    
    try {
        $reflection = new \ReflectionClass($transport);
        $serializeMethod = $reflection->getMethod('performSerialization');
        $deserializeMethod = $reflection->getMethod('performDeserialization');
        $serializeMethod->setAccessible(true);
        $deserializeMethod->setAccessible(true);
        
        $serialized = $serializeMethod->invoke($transport, $testData);
        $deserialized = $deserializeMethod->invoke($transport, $serialized);
        
        $success = $testData === $deserialized;
        echo "  Serialization test: " . ($success ? "PASS" : "FAIL") . "\n";
        
        if (!$success) {
            echo "    Original: " . var_export($testData, true) . "\n";
            echo "    Result: " . var_export($deserialized, true) . "\n";
        }
    } catch (\Throwable $e) {
        echo "  Serialization test: ERROR - " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}
