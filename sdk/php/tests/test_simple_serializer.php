<?php
declare(strict_types=1);

/**
 * Simple test to verify serializer availability
 */

echo "PHP Extension Availability Check\n";
echo "=================================\n\n";

// Check what's actually available
echo "Extension Status:\n";
echo "  igbinary: " . (extension_loaded('igbinary') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "  msgpack: " . (extension_loaded('msgpack') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "\n";

echo "Function Availability:\n";
echo "  igbinary_serialize: " . (function_exists('igbinary_serialize') ? 'YES' : 'NO') . "\n";
echo "  igbinary_unserialize: " . (function_exists('igbinary_unserialize') ? 'YES' : 'NO') . "\n";
echo "  msgpack_pack: " . (function_exists('msgpack_pack') ? 'YES' : 'NO') . "\n";
echo "  msgpack_unpack: " . (function_exists('msgpack_unpack') ? 'YES' : 'NO') . "\n";
echo "\n";

// Test serialization behavior
$testData = [
    'string' => 'Hello World',
    'number' => 42,
    'float' => 3.14,
    'boolean' => true,
    'null' => null,
    'array' => [1, 2, 3],
    'nested' => ['key' => 'value']
];

echo "Direct Serialization Tests:\n";

// Test native
$native_serialized = serialize($testData);
$native_deserialized = unserialize($native_serialized);
echo "  Native: " . ($testData === $native_deserialized ? 'PASS' : 'FAIL') . "\n";

// Test igbinary if available
if (function_exists('igbinary_serialize') && function_exists('igbinary_unserialize')) {
    $igbinary_serialized = igbinary_serialize($testData);
    $igbinary_deserialized = igbinary_unserialize($igbinary_serialized);
    echo "  igbinary: " . ($testData === $igbinary_deserialized ? 'PASS' : 'FAIL') . "\n";
    echo "    Size comparison - Native: " . strlen($native_serialized) . " bytes, igbinary: " . strlen($igbinary_serialized) . " bytes\n";
} else {
    echo "  igbinary: UNAVAILABLE\n";
}

// Test msgpack if available  
if (function_exists('msgpack_pack') && function_exists('msgpack_unpack')) {
    $msgpack_serialized = msgpack_pack($testData);
    $msgpack_deserialized = msgpack_unpack($msgpack_serialized);
    echo "  msgpack: " . ($testData === $msgpack_deserialized ? 'PASS' : 'FAIL') . "\n";
    echo "    Size comparison - Native: " . strlen($native_serialized) . " bytes, msgpack: " . strlen($msgpack_serialized) . " bytes\n";
} else {
    echo "  msgpack: UNAVAILABLE\n";
}

echo "\nValidation Logic Test:\n";

function validateSerializer(string $preferred): string {
    return match($preferred) {
        'igbinary' => (function_exists('igbinary_serialize') && function_exists('igbinary_unserialize')) ? 'igbinary' : 
                     ((function_exists('msgpack_pack') && function_exists('msgpack_unpack')) ? 'msgpack' : 'native'),
        'msgpack' => (function_exists('msgpack_pack') && function_exists('msgpack_unpack')) ? 'msgpack' : 'native',
        'native' => 'native',
        default => 'native'
    };
}

$tests = ['igbinary', 'msgpack', 'native', 'invalid'];
foreach ($tests as $requested) {
    $actual = validateSerializer($requested);
    echo "  Requested: $requested -> Actual: $actual\n";
}
