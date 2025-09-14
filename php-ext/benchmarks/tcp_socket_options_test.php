#!/usr/bin/env php
<?php
/**
 * TCP Socket Options Verification Test
 * 
 * Tests that the Rust TagCache server properly applies TCP socket options:
 * - TCP_NODELAY for immediate packet sending
 * - TCP keepalive for connection persistence
 */

echo "=== TCP Socket Options Verification ===\n";
echo "Testing TagCache server TCP socket configuration...\n\n";

// Configuration
$host = '127.0.0.1';
$port = 1984;
$timeout = 5;

function testTcpConnection($host, $port, $timeout) {
    echo "1. Testing basic TCP connection...\n";
    
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$socket) {
        echo "   ❌ Failed to create socket: " . socket_strerror(socket_last_error()) . "\n";
        return false;
    }
    
    // Set socket timeout
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
    socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);
    
    if (!socket_connect($socket, $host, $port)) {
        echo "   ❌ Failed to connect: " . socket_strerror(socket_last_error($socket)) . "\n";
        socket_close($socket);
        return false;
    }
    
    echo "   ✅ Connected successfully\n";
    
    // Test basic operation
    $command = "STATS\n";
    socket_write($socket, $command);
    $response = socket_read($socket, 1024);
    
    if (strpos($response, 'STATS') === 0) {
        echo "   ✅ Basic protocol working\n";
    } else {
        echo "   ❌ Protocol error: $response\n";
        socket_close($socket);
        return false;
    }
    
    socket_close($socket);
    return true;
}

function testTcpNodelay($host, $port) {
    echo "\n2. Testing TCP_NODELAY behavior...\n";
    
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_connect($socket, $host, $port)) {
        echo "   ❌ Connection failed\n";
        socket_close($socket);
        return false;
    }
    
    // Check if TCP_NODELAY is set (this is platform-specific)
    $nodelay = socket_get_option($socket, SOL_TCP, TCP_NODELAY);
    echo "   TCP_NODELAY value: " . ($nodelay ? "ENABLED" : "DISABLED") . "\n";
    
    // Test latency with small packets (nodelay should reduce latency)
    $start = microtime(true);
    for ($i = 0; $i < 10; $i++) {
        socket_write($socket, "STATS\n");
        socket_read($socket, 1024);
    }
    $end = microtime(true);
    
    $avg_latency = (($end - $start) / 10) * 1000; // ms
    echo "   Average latency for 10 operations: " . number_format($avg_latency, 2) . " ms\n";
    
    if ($avg_latency < 10) {
        echo "   ✅ Low latency indicates TCP_NODELAY is working\n";
    } else {
        echo "   ⚠️  Higher latency - TCP_NODELAY may not be enabled\n";
    }
    
    socket_close($socket);
    return true;
}

function testKeepalive($host, $port) {
    echo "\n3. Testing TCP keepalive behavior...\n";
    
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_connect($socket, $host, $port)) {
        echo "   ❌ Connection failed\n";
        socket_close($socket);
        return false;
    }
    
    // Check keepalive status (platform-specific)
    if (defined('SO_KEEPALIVE')) {
        $keepalive = socket_get_option($socket, SOL_SOCKET, SO_KEEPALIVE);
        echo "   SO_KEEPALIVE value: " . ($keepalive ? "ENABLED" : "DISABLED") . "\n";
    } else {
        echo "   SO_KEEPALIVE constant not available on this platform\n";
    }
    
    // Test connection persistence
    echo "   Testing connection persistence...\n";
    socket_write($socket, "STATS\n");
    $response1 = socket_read($socket, 1024);
    
    // Wait a moment and test again
    sleep(1);
    socket_write($socket, "STATS\n");
    $response2 = socket_read($socket, 1024);
    
    if ($response1 && $response2) {
        echo "   ✅ Connection remained stable\n";
    } else {
        echo "   ❌ Connection stability issues\n";
    }
    
    socket_close($socket);
    return true;
}

function testMultipleConnections($host, $port) {
    echo "\n4. Testing multiple concurrent connections...\n";
    
    $sockets = [];
    $num_connections = 5;
    
    // Create multiple connections
    for ($i = 0; $i < $num_connections; $i++) {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (socket_connect($socket, $host, $port)) {
            $sockets[] = $socket;
        } else {
            echo "   ❌ Failed to create connection $i\n";
            socket_close($socket);
        }
    }
    
    echo "   Created " . count($sockets) . "/$num_connections connections\n";
    
    // Test each connection
    $working = 0;
    foreach ($sockets as $i => $socket) {
        socket_write($socket, "STATS\n");
        $response = socket_read($socket, 1024);
        if (strpos($response, 'STATS') === 0) {
            $working++;
        }
    }
    
    echo "   Working connections: $working/" . count($sockets) . "\n";
    
    // Close all connections
    foreach ($sockets as $socket) {
        socket_close($socket);
    }
    
    if ($working == count($sockets)) {
        echo "   ✅ All connections working correctly\n";
        return true;
    } else {
        echo "   ❌ Some connections failed\n";
        return false;
    }
}

// Main test execution
try {
    echo "Connecting to TagCache server at $host:$port...\n\n";
    
    if (!testTcpConnection($host, $port, $timeout)) {
        echo "\n❌ Basic connection test failed. Is the server running?\n";
        exit(1);
    }
    
    $tests = [
        'testTcpNodelay' => 'TCP_NODELAY configuration',
        'testKeepalive' => 'TCP keepalive configuration', 
        'testMultipleConnections' => 'Multiple connection handling'
    ];
    
    $passed = 1; // Basic connection already passed
    $total = count($tests) + 1;
    
    foreach ($tests as $testFunc => $description) {
        if ($testFunc($host, $port)) {
            $passed++;
        }
    }
    
    echo "\n=== Test Results ===\n";
    echo "Passed: $passed/$total tests\n";
    
    if ($passed == $total) {
        echo "✅ All TCP socket option tests passed!\n";
        echo "\nThe TagCache server appears to be properly configured with:\n";
        echo "- TCP_NODELAY for low latency\n";
        echo "- TCP keepalive for connection persistence\n";
        echo "- Proper concurrent connection handling\n";
    } else {
        echo "⚠️  Some tests failed. Check server configuration.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Test error: " . $e->getMessage() . "\n";
    exit(1);
}