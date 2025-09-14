# TagCache PHP Extension Examples

This directory contains comprehensive examples demonstrating the TagCache PHP extension's capabilities.

## Quick Start

1. **Ensure TagCache server is running:**
   ```bash
   # From the main tagcache directory
   cargo run --release
   ```

2. **Build the PHP extension:**
   ```bash
   cd php-ext
   ./scripts/configure_build.sh
   ./scripts/build_with_serializers.sh
   ```

3. **Run examples:**
   ```bash
   # Basic usage
   php -d extension=./modules/tagcache.so examples/basic_usage.php
   
   # Bulk operations
   php -d extension=./modules/tagcache.so examples/bulk_operations.php
   
   # Advanced features
   php -d extension=./modules/tagcache.so examples/advanced_features.php
   ```

## Examples Overview

### 📚 basic_usage.php
**Purpose:** Introduction to core TagCache functionality
- Creating client connections
- Basic PUT/GET operations
- Working with tags
- Error handling basics
- Data type handling

**Best for:** First-time users, learning the API

### 📦 bulk_operations.php
**Purpose:** High-performance bulk operations
- Bulk PUT and GET operations
- Performance comparisons (single vs bulk)
- Chunked processing for large datasets
- Memory management
- Optimization techniques

**Best for:** High-throughput applications, performance optimization

### ⚡ advanced_features.php
**Purpose:** Advanced functionality and optimization
- Connection pooling configuration
- TTL management strategies
- Hierarchical tagging systems
- Error handling and recovery
- Performance monitoring
- Memory usage analysis

**Best for:** Production applications, advanced optimization

## Configuration Options

### Basic Configuration
```php
$client = tagcache_create([
    'host' => '127.0.0.1',
    'port' => 1984,
    'timeout' => 1.0
]);
```

### Optimized Configuration
```php
$client = tagcache_create([
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 32,        // Connection pooling
    'keep_alive' => true,     // Reuse connections
    'tcp_nodelay' => true,    // Reduce latency
    'timeout' => 0.5,         // Fast timeouts
    'serialize_format' => 'native'  // Serialization method
]);
```

## Performance Tips

1. **Use bulk operations** when possible (5-10x performance improvement)
2. **Enable connection pooling** for concurrent applications
3. **Set appropriate TTL values** based on data access patterns
4. **Use hierarchical tags** for flexible data organization
5. **Monitor memory usage** in production environments

## Common Use Cases

### Session Storage
```php
// Store user session with 1-hour TTL
tagcache_put($client, "session:$user_id", $session_data, ['sessions', 'active'], 3600);
```

### API Response Caching
```php
// Cache API response with 5-minute TTL
tagcache_put($client, "api:$endpoint", $response, ['api', 'cache'], 300);
```

### Tag-based Invalidation
```php
// Invalidate all user-related data
tagcache_invalidate_tag($client, "user:$user_id");
```

## Error Handling

Always check return values and handle errors gracefully:

```php
$client = tagcache_create($config);
if (!$client) {
    throw new Exception("Failed to connect to TagCache server");
}

$result = tagcache_put($client, $key, $value, $tags, $ttl);
if (!$result) {
    error_log("Failed to store data for key: $key");
}

// Always close connections
tagcache_close($client);
```

## Troubleshooting

### Extension Not Loaded
```bash
# Check if extension is loaded
php -m | grep tagcache

# Load extension manually
php -d extension=./modules/tagcache.so your_script.php
```

### Connection Issues
- Ensure TagCache server is running on the correct port (default: 1984)
- Check firewall settings
- Verify host and port configuration

### Performance Issues
- Use bulk operations for multiple items
- Enable connection pooling
- Adjust timeout values
- Monitor memory usage

## Next Steps

- Explore the `benchmarks/` directory for performance testing
- Check `docs/` for detailed documentation
- Review `tests/` for additional usage patterns
- Use `scripts/` for build and development tools