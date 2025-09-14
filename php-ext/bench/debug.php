<?php
/**
 * Debug script for TagCache PHP Extension
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

// Connect
$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 8,
    'connect_timeout_ms' => 100,
    'read_timeout_ms' => 100
]);

if (!$client) {
    die("Failed to connect to TagCache server\n");
}

echo "=== TagCache Extension Debug ===\n\n";

// Test 1: Simple GET/PUT
echo "1. Testing simple operations:\n";

echo "  PUT test_key... ";
$result = tagcache_put($client, "test_key", "test_value", [], 3600);
echo $result ? "OK\n" : "FAIL\n";

echo "  GET test_key... ";
$value = tagcache_get($client, "test_key");
echo ($value === "test_value") ? "OK\n" : "FAIL (got: " . var_export($value, true) . ")\n";

// Test 2: Single bulk operation
echo "\n2. Testing single bulk PUT:\n";

$items = [];
for ($i = 0; $i < 5; $i++) {
    $items["bulk_test_$i"] = "value_$i";
}

echo "  Bulk PUT " . count($items) . " items... ";
$result = tagcache_bulk_put($client, $items, 3600);
echo "Result: $result (expected: " . count($items) . ")\n";

if ($result != count($items)) {
    echo "  Bulk PUT failed! Debugging individual items:\n";
    foreach ($items as $key => $value) {
        echo "    PUT $key... ";
        $single_result = tagcache_put($client, $key, $value, [], 3600);
        echo $single_result ? "OK" : "FAIL";
        
        echo " GET $key... ";
        $get_result = tagcache_get($client, $key);
        echo ($get_result === $value) ? "OK\n" : "FAIL\n";
    }
}

// Test 3: Check if server is responding
echo "\n3. Server connectivity test:\n";
echo "  Simple ping with GET... ";
$ping_result = tagcache_get($client, "nonexistent_key_12345");
echo ($ping_result === null) ? "OK (returns null for missing key)\n" : "FAIL\n";

tagcache_close($client);
echo "\nDebug completed.\n";