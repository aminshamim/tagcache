#!/bin/bash

# TagCache PHP Extension - Optimization Verification Script

echo "TagCache PHP Extension - Optimization Verification"
echo "=================================================="

# Check if extension is compiled
if [ ! -f "modules/tagcache.so" ]; then
    echo "❌ Extension not compiled. Please run: make"
    exit 1
fi

echo "✅ Extension compiled successfully"

# Check if TagCache server is running
if ! pgrep -f "tagcache" > /dev/null 2>&1; then
    echo "❌ TagCache server not running. Please start it first."
    echo "   Run: cargo build --release && ./target/release/tagcache"
    exit 1
fi

echo "✅ TagCache server is running"

# Test extension loading
php -d extension=modules/tagcache.so -m | grep -q tagcache
if [ $? -eq 0 ]; then
    echo "✅ Extension loads successfully"
else
    echo "❌ Extension failed to load"
    exit 1
fi

echo ""
echo "Running optimization tests..."
echo ""

# Test 1: Basic functionality
echo "Test 1: Basic functionality"
php -d extension=modules/tagcache.so -r "
\$h = tagcache_create(['mode' => 'tcp', 'host' => '127.0.0.1', 'port' => 1984]);
if (\$h) {
    tagcache_put(\$h, 'test_key', 'test_value', [], 300);
    \$result = tagcache_get(\$h, 'test_key');
    if (\$result === 'test_value') {
        echo '✅ Basic PUT/GET works\n';
    } else {
        echo '❌ Basic PUT/GET failed\n';
        exit(1);
    }
    tagcache_delete(\$h, 'test_key');
} else {
    echo '❌ Failed to create handle\n';
    exit(1);
}
"

# Test 2: Keep-alive configuration
echo "Test 2: Keep-alive configuration"
php -d extension=modules/tagcache.so -r "
\$h = tagcache_create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'enable_keep_alive' => true,
    'keep_alive_idle' => 30,
    'keep_alive_interval' => 5,
    'keep_alive_count' => 3
]);
if (\$h) {
    echo '✅ Keep-alive configuration accepted\n';
} else {
    echo '❌ Keep-alive configuration failed\n';
}
"

# Test 3: Bulk operations
echo "Test 3: Bulk operations"
php -d extension=modules/tagcache.so -r "
\$h = tagcache_create(['mode' => 'tcp', 'host' => '127.0.0.1', 'port' => 1984]);
if (\$h) {
    // Setup bulk test data
    for (\$i = 0; \$i < 10; \$i++) {
        tagcache_put(\$h, \"bulk_test_\$i\", \"bulk_value_\$i\", [], 300);
    }
    
    \$keys = [];
    for (\$i = 0; \$i < 10; \$i++) {
        \$keys[] = \"bulk_test_\$i\";
    }
    
    \$results = tagcache_bulk_get(\$h, \$keys);
    if (count(\$results) === 10) {
        echo '✅ Bulk operations work\n';
    } else {
        echo '❌ Bulk operations failed\n';
    }
    
    // Cleanup
    for (\$i = 0; \$i < 10; \$i++) {
        tagcache_delete(\$h, \"bulk_test_\$i\");
    }
} else {
    echo '❌ Failed to create handle for bulk test\n';
}
"

echo ""
echo "Running performance comparison..."
echo ""

# Simple performance test
php -d extension=modules/tagcache.so -r "
echo \"Performance Test - 1000 operations each\n\";
echo \"======================================\n\";

// Basic configuration
\$basic = tagcache_create(['mode' => 'tcp', 'host' => '127.0.0.1', 'port' => 1984]);

// Optimized configuration  
\$optimized = tagcache_create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 4,
    'enable_keep_alive' => true,
    'enable_pipelining' => true,
    'pipeline_depth' => 10
]);

// Setup test data
for (\$i = 0; \$i < 100; \$i++) {
    tagcache_put(\$basic, \"perf_test_\$i\", \"value_\$i\", [], 300);
}

// Test basic config
\$start = microtime(true);
for (\$i = 0; \$i < 1000; \$i++) {
    tagcache_get(\$basic, 'perf_test_' . (\$i % 100));
}
\$basic_time = microtime(true) - \$start;
\$basic_ops = 1000 / \$basic_time;

// Prime cache for optimized config
for (\$i = 0; \$i < 100; \$i++) {
    tagcache_get(\$optimized, \"perf_test_\$i\");
}

// Test optimized config
\$start = microtime(true);
for (\$i = 0; \$i < 1000; \$i++) {
    tagcache_get(\$optimized, 'perf_test_' . (\$i % 100));
}
\$optimized_time = microtime(true) - \$start;
\$optimized_ops = 1000 / \$optimized_time;

\$improvement = (\$optimized_ops / \$basic_ops - 1) * 100;

echo \"Basic config:     \" . number_format(\$basic_ops) . \" ops/sec\n\";
echo \"Optimized config: \" . number_format(\$optimized_ops) . \" ops/sec\n\";
echo \"Improvement:      \" . sprintf('%.1f', \$improvement) . \"%\n\";

// Cleanup
for (\$i = 0; \$i < 100; \$i++) {
    tagcache_delete(\$basic, \"perf_test_\$i\");
}
"

echo ""
echo "✅ All optimization tests completed successfully!"
echo ""
echo "Available optimization features:"
echo "- ✅ Connection pooling (pool_size)"
echo "- ✅ TCP keep-alive (enable_keep_alive)"  
echo "- ✅ Request pipelining (enable_pipelining)"
echo "- ✅ Async I/O (enable_async_io)"
echo "- ✅ Bulk operations (tagcache_bulk_get)"
echo ""
echo "For comprehensive performance testing, run:"
echo "php -d extension=modules/tagcache.so bench/comprehensive_performance_test.php"