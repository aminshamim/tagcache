<?php
/**
 * Simple debug script to test basic PUT/GET with server
 */

if (!extension_loaded('tagcache')) {
    die("TagCache extension not loaded\n");
}

// Connect
$client = tagcache_create([
    'mode' => 'tcp',
    'tcp_host' => '127.0.0.1',
    'tcp_port' => 1984,
    'pool_size' => 1,
    'connect_timeout_ms' => 1000,
    'read_timeout_ms' => 1000
]);

if (!$client) {
    die("Failed to connect to TagCache server\n");
}

echo "=== Basic PUT/GET Test ===\n";

// Test 1: Simple string
echo "Test 1: Simple string\n";
echo "  PUT 'hello' -> 'world'... ";
$result = tagcache_put($client, "hello", "world", [], 3600);
echo $result ? "OK\n" : "FAIL\n";

echo "  GET 'hello'... ";
$value = tagcache_get($client, "hello");
echo "Result: " . var_export($value, true) . "\n";
echo "  Expected: 'world', Got: " . ($value === "world" ? "MATCH" : "NO MATCH") . "\n\n";

// Test 2: Number
echo "Test 2: Number\n";
echo "  PUT 'num' -> 42... ";
$result = tagcache_put($client, "num", 42, [], 3600);
echo $result ? "OK\n" : "FAIL\n";

echo "  GET 'num'... ";
$value = tagcache_get($client, "num");
echo "Result: " . var_export($value, true) . "\n";
echo "  Expected: 42, Got: " . ($value == 42 ? "MATCH" : "NO MATCH") . "\n\n";

// Test 3: Non-existent key  
echo "Test 3: Non-existent key\n";
echo "  GET 'nonexistent'... ";
$value = tagcache_get($client, "nonexistent");
echo "Result: " . var_export($value, true) . "\n";
echo "  Expected: NULL, Got: " . ($value === null ? "MATCH" : "NO MATCH") . "\n\n";

tagcache_close($client);
echo "Debug completed.\n";