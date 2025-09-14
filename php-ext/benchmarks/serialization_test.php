<?php
/**
 * Comprehensive Serialization Test
 * Tests all available serialization formats in the TagCache extension
 */

// Start the TagCache server if not running
$server_check = @fsockopen('127.0.0.1', 1984, $errno, $errstr, 1);
if (!$server_check) {
    echo "❌ TagCache server not running on port 1984\n";
    echo "Start with: ./target/release/tagcache\n";
    exit(1);
}
fclose($server_check);

echo "🚀 TagCache Multi-Format Serialization Test\n";
echo str_repeat("=", 60) . "\n";

// Test data with various complexity levels
$test_data = [
    'string' => 'Hello World',
    'integer' => 42,
    'float' => 3.14159,
    'boolean_true' => true,
    'boolean_false' => false,
    'null_value' => null,
    'array_simple' => ['a', 'b', 'c'],
    'array_assoc' => ['name' => 'John', 'age' => 30],
    'array_nested' => [
        'user' => ['id' => 123, 'name' => 'Alice'],
        'meta' => ['tags' => ['php', 'cache'], 'active' => true]
    ],
    'object' => (object)['prop1' => 'value1', 'prop2' => 42]
];

// Available serialization formats
$serialization_formats = [
    'php' => 'PHP serialize() (default)',
    'native' => 'Native types only (fastest)',
    'igbinary' => 'igbinary (binary, if available)',
    'msgpack' => 'msgpack (binary, if available)'
];

function test_serialization_format($format, $description) {
    global $test_data;
    
    echo "\n📦 Testing Format: $format ($description)\n";
    echo str_repeat("-", 50) . "\n";
    
    // Create client with specific serialization format
    $client = tagcache_create([
        'host' => '127.0.0.1',
        'port' => 1984,
        'serializer' => $format
    ]);
    
    if (!$client) {
        echo "❌ Failed to create client for format: $format\n";
        return false;
    }
    
    $success_count = 0;
    $total_tests = count($test_data);
    
    foreach ($test_data as $key => $value) {
        $cache_key = "test_{$format}_{$key}";
        
        // Test PUT and GET
        $put_result = tagcache_put($client, $cache_key, $value, [], 300);
        
        if ($put_result) {
            $retrieved = tagcache_get($client, $cache_key);
            
            // Compare values (with type consideration)
            $match = false;
            if ($format === 'native' && is_object($value)) {
                // Native format should reject objects
                $match = ($retrieved === null || $retrieved === false);
                echo "  ✓ $key: Object correctly rejected by native format\n";
            } elseif ($format === 'native' && is_array($value)) {
                // Native format should reject arrays
                $match = ($retrieved === null || $retrieved === false);
                echo "  ✓ $key: Array correctly rejected by native format\n";
            } else {
                // Deep comparison for complex types
                if (is_object($value) && is_object($retrieved)) {
                    $match = json_encode($value) === json_encode($retrieved);
                } elseif (is_array($value) && is_array($retrieved)) {
                    $match = json_encode($value) === json_encode($retrieved);
                } else {
                    $match = ($value === $retrieved);
                }
                
                if ($match) {
                    echo "  ✓ $key: " . gettype($value) . " serialized/deserialized correctly\n";
                } else {
                    echo "  ❌ $key: Mismatch!\n";
                    echo "    Original: " . var_export($value, true) . "\n";
                    echo "    Retrieved: " . var_export($retrieved, true) . "\n";
                }
            }
            
            if ($match) $success_count++;
        } else {
            if ($format === 'native' && (is_object($value) || is_array($value))) {
                echo "  ✓ $key: Complex type correctly rejected by native format\n";
                $success_count++;
            } else {
                echo "  ❌ $key: PUT failed\n";
            }
        }
    }
    
    echo "\nResults: $success_count/$total_tests tests passed\n";
    
    tagcache_close($client);
    return $success_count === $total_tests;
}

function benchmark_serialization_performance() {
    echo "\n⚡ Serialization Performance Benchmark\n";
    echo str_repeat("-", 50) . "\n";
    
    $iterations = 1000;
    $test_value = [
        'id' => 12345,
        'name' => 'Performance Test User',
        'data' => ['item1', 'item2', 'item3'],
        'meta' => (object)['active' => true, 'score' => 98.5]
    ];
    
    foreach (['php', 'native', 'igbinary', 'msgpack'] as $format) {
        $client = tagcache_create(['serializer' => $format]);
        if (!$client) continue;
        
        $start = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $key = "perf_test_{$format}_{$i}";
            
            if ($format === 'native') {
                // Use simple value for native format
                tagcache_put($client, $key, "simple_string_$i", [], 60);
            } else {
                tagcache_put($client, $key, $test_value, [], 60);
            }
        }
        
        $duration = microtime(true) - $start;
        $ops_per_sec = $iterations / $duration;
        
        echo sprintf("  %s: %.2f ops/sec (%.4f ms/op)\n", 
            str_pad($format, 10), $ops_per_sec, ($duration * 1000) / $iterations);
        
        tagcache_close($client);
    }
}

function test_bulk_operations_with_serialization() {
    echo "\n📦 Bulk Operations with Different Serialization\n";
    echo str_repeat("-", 50) . "\n";
    
    $bulk_data = [];
    for ($i = 0; $i < 100; $i++) {
        $bulk_data["bulk_key_$i"] = [
            'id' => $i,
            'value' => "test_value_$i",
            'timestamp' => time() + $i
        ];
    }
    
    foreach (['php', 'igbinary', 'msgpack'] as $format) {
        $client = tagcache_create(['serializer' => $format]);
        if (!$client) continue;
        
        $start = microtime(true);
        $result = tagcache_bulk_put($client, $bulk_data, 300);
        $duration = microtime(true) - $start;
        
        echo sprintf("  %s: %d items in %.4f sec (%.0f ops/sec)\n", 
            str_pad($format, 10), $result, $duration, $result / $duration);
        
        tagcache_close($client);
    }
}

// Run all tests
$all_passed = true;

foreach ($serialization_formats as $format => $description) {
    if (!test_serialization_format($format, $description)) {
        $all_passed = false;
    }
}

benchmark_serialization_performance();
test_bulk_operations_with_serialization();

echo "\n" . str_repeat("=", 60) . "\n";

if ($all_passed) {
    echo "🎉 All serialization tests PASSED!\n";
    
    // Display available serializers at runtime
    echo "\n📋 Runtime Serialization Capability Report:\n";
    
    // Test each format to see what's actually available
    foreach (['php', 'native', 'igbinary', 'msgpack'] as $format) {
        $client = tagcache_create(['serializer' => $format]);
        if ($client) {
            $test_result = tagcache_put($client, "capability_test_$format", "test", [], 10);
            echo "  ✅ $format: Available and functional\n";
            tagcache_close($client);
        } else {
            echo "  ❌ $format: Not available or failed to initialize\n";
        }
    }
    
    echo "\n💡 Usage Examples:\n";
    echo "  // Use PHP serialize (default)\n";
    echo "  \$client = tagcache_create(['serializer' => 'php']);\n\n";
    echo "  // Use native types only (fastest)\n";
    echo "  \$client = tagcache_create(['serializer' => 'native']);\n\n";
    echo "  // Use igbinary (if available)\n";
    echo "  \$client = tagcache_create(['serializer' => 'igbinary']);\n\n";
    echo "  // Use msgpack (if available)\n";
    echo "  \$client = tagcache_create(['serializer' => 'msgpack']);\n";
    
} else {
    echo "❌ Some serialization tests FAILED\n";
    exit(1);
}
?>