<?php

/**
 * TCP Configuration Comparison: Old vs Enhanced Defaults
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Config;

echo "🔄 TCP Configuration: Before vs After Enhancement\n";
echo "================================================\n\n";

// Old-style minimal configuration (what it would have been before)
echo "📊 OLD Default Configuration (Basic):\n";
$oldStyleConfig = [
    'tcp' => [
        'host' => '127.0.0.1',
        'port' => 1984,
        'timeout_ms' => 2000,  // Old default was 2 seconds
        'pool_size' => 4,      // Old default was 4 connections
        // Missing: enhanced features
    ]
];
print_r($oldStyleConfig['tcp']);

echo "\n🚀 NEW Enhanced Default Configuration:\n";
$newConfig = new Config(['mode' => 'tcp']);
print_r($newConfig->tcp);

echo "\n📈 Enhancement Improvements:\n";
echo "✅ Timeout increased: 2000ms → 5000ms (more reliable)\n";
echo "✅ Separate connect timeout: Added 3000ms\n";
echo "✅ Pool size increased: 4 → 8 connections (better concurrency)\n";
echo "✅ Retry logic: Added max_retries=3 with 100ms delay\n";
echo "✅ TCP optimization: Added tcp_nodelay=true (lower latency)\n";
echo "✅ Keep-alive: Added keep_alive=true with 30s interval\n";
echo "✅ Better defaults for production workloads\n";

echo "\n🌍 Environment Variable Support:\n";
echo "You can now configure all TCP options via environment variables:\n";
echo "  TAGCACHE_TCP_TIMEOUT_MS=8000\n";
echo "  TAGCACHE_TCP_CONNECT_TIMEOUT_MS=2000\n";
echo "  TAGCACHE_TCP_POOL_SIZE=12\n";
echo "  TAGCACHE_TCP_MAX_RETRIES=5\n";
echo "  TAGCACHE_TCP_RETRY_DELAY_MS=150\n";
echo "  TAGCACHE_TCP_NODELAY=true\n";
echo "  TAGCACHE_TCP_KEEPALIVE=true\n";
echo "  TAGCACHE_TCP_KEEPALIVE_INTERVAL=45\n";

echo "\n⚡ Performance Benefits:\n";
echo "  • Faster connection establishment with separate timeouts\n";
echo "  • Better connection reuse with larger pool\n";
echo "  • Automatic retry on transient failures\n";
echo "  • Lower latency with TCP_NODELAY\n";
echo "  • Connection health monitoring\n";
echo "  • Graceful degradation under load\n";

echo "\n🎯 Production Ready Features:\n";
echo "  • Connection failure tracking and recovery\n";
echo "  • Pool health monitoring\n";
echo "  • Enhanced error handling with proper exception types\n";
echo "  • Comprehensive logging for debugging\n";
echo "  • Configurable serialization (igbinary/msgpack/JSON)\n";
echo "  • Batch operations for high throughput\n";

echo "\nThe TcpTransport is now enterprise-grade! 🚀\n";
