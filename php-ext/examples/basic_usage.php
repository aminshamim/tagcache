<?php
/**
 * Basic TagCache PHP Extension Usage Example
 * 
 * This example demonstrates the core functionality of the TagCache extension:
 * - Creating a client connection
 * - Storing and retrieving data
 * - Using tags for organization
 * - Basic error handling
 */

// Check if extension is loaded
if (!extension_loaded('tagcache')) {
    die("TagCache extension is not loaded!\n");
}

echo "🚀 TagCache Basic Usage Example\n";
echo "===============================\n\n";

// Create a client connection
$client = tagcache_create([
    'host' => '127.0.0.1',
    'port' => 1984,
    'timeout' => 1.0
]);

if (!$client) {
    die("❌ Failed to connect to TagCache server\n");
}

echo "✅ Connected to TagCache server\n\n";

// Basic PUT and GET operations
echo "📝 Basic Operations:\n";
echo "-------------------\n";

// Store a simple value
$key = 'user:1234';
$value = 'John Doe';
$tags = ['users', 'active'];
$ttl = 3600; // 1 hour

$result = tagcache_put($client, $key, $value, $tags, $ttl);
echo "PUT $key: " . ($result ? "✅ Success" : "❌ Failed") . "\n";

// Retrieve the value
$retrieved = tagcache_get($client, $key);
echo "GET $key: " . ($retrieved ? "✅ $retrieved" : "❌ Not found") . "\n\n";

// Working with different data types
echo "🔢 Data Types:\n";
echo "-------------\n";

// Store an array (will be serialized)
$user_data = [
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
    'role' => 'admin'
];

tagcache_put($client, 'user:5678', $user_data, ['users', 'admins'], 3600);
$retrieved_data = tagcache_get($client, 'user:5678');
echo "Stored array: " . print_r($retrieved_data, true) . "\n";

// Store a number
tagcache_put($client, 'counter:visits', 42, ['stats'], 300);
$visits = tagcache_get($client, 'counter:visits');
echo "Visits counter: $visits\n\n";

// Tag-based operations
echo "🏷️  Tag Operations:\n";
echo "------------------\n";

// Store multiple items with tags
$products = [
    'product:1' => ['name' => 'Laptop', 'price' => 999.99],
    'product:2' => ['name' => 'Mouse', 'price' => 29.99],
    'product:3' => ['name' => 'Keyboard', 'price' => 79.99]
];

foreach ($products as $key => $product) {
    tagcache_put($client, $key, $product, ['products', 'electronics'], 3600);
}

// Invalidate all products
$invalidated = tagcache_invalidate_tag($client, 'products');
echo "Invalidated $invalidated items with 'products' tag\n";

// Verify they're gone
$laptop = tagcache_get($client, 'product:1');
echo "Product 1 after invalidation: " . ($laptop ? "Still exists" : "❌ Removed") . "\n\n";

// Error handling
echo "⚠️  Error Handling:\n";
echo "------------------\n";

// Try to get a non-existent key
$missing = tagcache_get($client, 'does-not-exist');
echo "Non-existent key: " . ($missing === false ? "❌ Not found (expected)" : "Unexpected result") . "\n";

// Try invalid operations
$invalid_result = tagcache_put($client, '', 'empty key test', [], 3600);
echo "Empty key PUT: " . ($invalid_result ? "Unexpected success" : "❌ Failed (expected)") . "\n\n";

// Clean up and close
echo "🧹 Cleanup:\n";
echo "----------\n";

tagcache_close($client);
echo "✅ Connection closed\n";

echo "\n🎉 Basic example completed successfully!\n";
echo "\n💡 Next steps:\n";
echo "  - Try examples/bulk_operations.php for bulk operations\n";
echo "  - See examples/advanced_features.php for advanced usage\n";
echo "  - Run benchmarks/ for performance testing\n";
?>