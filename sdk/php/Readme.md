# TagCache PHP SDK

A high-performance PHP SDK for TagCache with support for both HTTP and TCP transports. Built for production environments with automatic credential loading, connection pooling, and comprehensive error handling.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net/)
[![Test Coverage](https://img.shields.io/badge/coverage-97%25-green.svg)](phpunit.xml)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- ✅ **Dual Transport Support**: HTTP (REST API) and TCP (binary protocol) 
- ✅ **Auto-Discovery**: Automatic credential loading from `credential.txt`
- ✅ **Connection Pooling**: Optimized TCP connection management
- ✅ **Authentication**: Bearer token and Basic auth support
- ✅ **Framework Ready**: Laravel, Symfony, CakePHP integrations
- ✅ **Production Tested**: Comprehensive test suite with 97%+ coverage
- ✅ **High Performance**: 2000+ ops/sec on modern hardware
- ✅ **PSR-4 Compatible**: Clean autoloading and namespacing

## Installation

```bash
composer require tagcache/sdk
```

## Quick Start

### Basic Usage

```php
<?php
use TagCache\Client;
use TagCache\Config;

// Auto-configuration from environment/credentials
$client = new Client(new Config());

// Store data with tags and TTL
$client->put('user:123', ['name' => 'Alice', 'email' => 'alice@example.com'], 3600000, ['user', 'profile']);

// Retrieve data
$userData = $client->get('user:123');

// Find keys by tag
$userKeys = $client->keysByTag('user');

// Bulk operations for efficiency
$users = $client->bulkGet(['user:123', 'user:456', 'user:789']);
```

### Advanced Configuration

```php
<?php
use TagCache\Client;
use TagCache\Config;

$config = new Config([
    'mode' => 'auto', // 'http', 'tcp', or 'auto'
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 5000,
        'max_retries' => 3,
        'connection_pool_size' => 10,
    ],
    'tcp' => [
        'host' => 'localhost',
        'port' => 1984,
        'timeout_ms' => 2000,
        'pool_size' => 8,
        'persistent' => true,
    ],
    'auth' => [
        'username' => 'your_username',
        'password' => 'your_password',
        'auto_login' => true,
    ]
]);

$client = new Client($config);
```

## Configuration Options

### Transport Modes

| Mode | Description | Use Case |
|------|-------------|----------|
| `http` | RESTful HTTP API | Web applications, debugging |
| `tcp` | Binary TCP protocol | High-performance applications |
| `auto` | Try TCP, fallback to HTTP | Recommended for most cases |

### HTTP Configuration

```php
'http' => [
    'base_url' => 'http://localhost:8080',     // TagCache server URL
    'timeout_ms' => 5000,                      // Request timeout
    'max_retries' => 3,                        // Retry attempts
    'retry_delay_ms' => 200,                   // Delay between retries
    'connection_pool_size' => 10,              // HTTP connection pool
    'keep_alive' => true,                      // Keep connections alive
    'user_agent' => 'TagCache-PHP-SDK/1.0',   // Custom User-Agent
]
```

### TCP Configuration

```php
'tcp' => [
    'host' => 'localhost',                     // TagCache TCP host
    'port' => 1984,                           // TagCache TCP port
    'timeout_ms' => 2000,                     // Socket timeout
    'pool_size' => 8,                         // Connection pool size
    'connect_timeout_ms' => 1000,             // Connection timeout
    'persistent' => true,                     // Use persistent connections
]
```

### Environment Variables

The SDK automatically reads environment variables:

```bash
# Transport selection
TAGCACHE_MODE=auto

# HTTP settings
TAGCACHE_HTTP_URL=http://localhost:8080
TAGCACHE_HTTP_TIMEOUT=5000

# TCP settings  
TAGCACHE_TCP_HOST=localhost
TAGCACHE_TCP_PORT=1984
TAGCACHE_TCP_TIMEOUT=2000
TAGCACHE_TCP_POOL_SIZE=8

# Authentication
TAGCACHE_USERNAME=your_username
TAGCACHE_PASSWORD=your_password
TAGCACHE_TOKEN=your_bearer_token
```

## API Reference

### Core Operations

```php
// Store a value with optional TTL and tags
$client->put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool

// Retrieve a value
$client->get(string $key): mixed  // Returns null if not found

// Delete a key
$client->delete(string $key): bool

// Cache-or-compute pattern
$value = $client->getOrSet('expensive:calculation', function($key) {
    return performExpensiveCalculation();
}, 3600000, ['computation', 'cache']);
```

### Bulk Operations

```php
// Get multiple keys at once
$results = $client->bulkGet(['key1', 'key2', 'key3']): array

// Delete multiple keys
$deletedCount = $client->bulkDelete(['key1', 'key2', 'key3']): int
```

### Tag-Based Operations

```php
// Find keys that have ANY of the specified tags
$keys = $client->keysByTagsAny(['user', 'admin']): array

// Find keys that have ALL of the specified tags  
$keys = $client->keysByTagsAll(['user', 'active']): array

// Find keys by single tag
$keys = $client->keysByTag('user'): array

// Invalidate all keys with specific tags
$invalidatedCount = $client->invalidateTags(['user', 'cache']): int

// Invalidate specific keys
$invalidatedCount = $client->invalidateKeys(['user:123', 'user:456']): int
```

### Search & Discovery

```php
// Advanced search with patterns and filters
$results = $client->search([
    'pattern' => 'user:*',           // Key pattern matching
    'tag_any' => ['user', 'admin'],  // Keys with any of these tags
    'tag_all' => ['active'],         // Keys with all of these tags  
    'limit' => 100,                  // Limit results
]): array

// List all keys (paginated)
$keys = $client->list(100): array
```

### Administration

```php
// Get cache statistics
$stats = $client->stats(): array
// Returns: ['hits' => 1234, 'misses' => 56, 'puts' => 890, ...]

// Health check
$health = $client->health(): array
// Returns: ['status' => 'ok', 'transport' => 'tcp']

// Clear entire cache
$clearedCount = $client->flush(): int

// Authentication management
$client->login('username', 'password'): bool
$credentials = $client->rotateCredentials(): array
```

## Performance Benchmarks

Based on comprehensive testing with the TagCache server:

| Transport | Operation | Performance |
|-----------|-----------|-------------|
| HTTP | PUT | ~1,978 ops/sec |
| HTTP | GET | ~2,277 ops/sec |  
| TCP | PUT | ~17,000+ ops/sec |
| TCP | GET | ~15,000+ ops/sec |

### Performance Tips

1. **Use TCP in Production**: 8-10x faster than HTTP
2. **Enable Connection Pooling**: Reuse TCP connections
3. **Batch Operations**: Use `bulkGet`/`bulkDelete` for multiple keys
4. **Optimize TTL**: Set appropriate expiration times
5. **Tag Strategically**: Use tags for efficient invalidation

## Framework Integration

### Laravel

Create a service provider:

```php
<?php
// app/Providers/TagCacheServiceProvider.php

use TagCache\Client;
use TagCache\Config;

class TagCacheServiceProvider extends ServiceProvider 
{
    public function register() 
    {
        $this->app->singleton(Client::class, function ($app) {
            return new Client(Config::fromEnv());
        });
    }
}
```

Usage in controllers:

```php
<?php

class UserController extends Controller
{
    public function __construct(private Client $cache) {}
    
    public function show($id)
    {
        $user = $this->cache->getOrSet("user:$id", function() use ($id) {
            return User::find($id);
        }, 3600000, ['user']);
        
        return response()->json($user);
    }
}
```

### Symfony

Register as a service in `services.yaml`:

```yaml
services:
    TagCache\Client:
        factory: ['TagCache\Client', 'create']
        arguments:
            - '@TagCache\Config'
    
    TagCache\Config:
        factory: ['TagCache\Config', 'fromEnv']
```

## Error Handling

The SDK provides specific exceptions for different error conditions:

```php
<?php
use TagCache\Exceptions\{
    NotFoundException,
    TimeoutException, 
    ConnectionException,
    AuthenticationException,
    ServerException
};

try {
    $value = $client->get('nonexistent:key');
} catch (NotFoundException $e) {
    // Key not found
} catch (TimeoutException $e) {
    // Request timed out
} catch (ConnectionException $e) {
    // Cannot connect to server
} catch (AuthenticationException $e) {
    // Invalid credentials
} catch (ServerException $e) {
    // Server error (5xx)
}
```

## Testing

Run the test suite:

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit --testsuite=unit
./vendor/bin/phpunit --testsuite=feature  
./vendor/bin/phpunit --testsuite=integration
./vendor/bin/phpunit --testsuite=transport
./vendor/bin/phpunit --testsuite=performance

# Static analysis
./vendor/bin/phpstan analyse src tests --level=8
```

## Development

### Running Examples

```bash
# Basic usage example
php examples/basic.php

# Comprehensive feature test
php comprehensive_test.php

# Performance benchmarking
php run_tests.php
```

### Code Quality

The project maintains high code quality standards:

- **PHPStan Level 8**: Strict static analysis
- **PSR-4 Autoloading**: Clean namespace structure
- **Comprehensive Tests**: Unit, feature, integration, and performance tests
- **Type Safety**: Full PHP 8.1+ type declarations

## Troubleshooting

### Connection Issues

1. **Check server status**: Ensure TagCache is running on expected ports
2. **Verify credentials**: Check `credential.txt` or environment variables
3. **Network connectivity**: Test with `telnet localhost 1984` (TCP) or `curl http://localhost:8080/health` (HTTP)

### Performance Issues

1. **Use TCP transport**: Set `mode => 'tcp'` for best performance  
2. **Tune timeouts**: Adjust `timeout_ms` based on network conditions
3. **Connection pooling**: Increase `pool_size` for high-concurrency applications
4. **Batch operations**: Use `bulkGet`/`bulkDelete` instead of individual calls

### Debug Mode

Enable debug logging:

```php
$config = new Config([
    'http' => ['debug' => true],
    'tcp' => ['debug' => true],
]);
```

## License

MIT License. See [LICENSE](LICENSE) file for details.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Run tests (`./vendor/bin/phpunit`) 
4. Commit changes (`git commit -am 'Add amazing feature'`)
5. Push to branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

## Support

- 📖 **Documentation**: This README and inline code comments
- 🐛 **Bug Reports**: [GitHub Issues](https://github.com/aminshamim/tagcache/issues)
- 💡 **Feature Requests**: [GitHub Discussions](https://github.com/aminshamim/tagcache/discussions)
- 📧 **Email**: [your-email@domain.com](mailto:your-email@domain.com)
