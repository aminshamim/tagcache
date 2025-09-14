# TagCache PHP Extension# TagCache PHP Native Extension (Experimental)



A high-performance PHP extension for the TagCache in-memory cache server, featuring connection pooling, bulk operations, and tag-based data organization.High-performance, low-latency native PHP 8.4 extension for TagCache. Provides direct TCP protocol access plus lightweight HTTP fallback without per-call userland overhead.



## 🚀 Quick Start## Goals

- Minimize latency vs userland SDK

### Prerequisites- Persistent TCP connection pooling in C

- PHP 8.0+ with development headers- Zero-copy string handling where possible

- TagCache server running- Automatic (de)serialization markers compatible with SDK (`__TC_*` scheme)

- GCC or compatible compiler- Feature coverage: put/get/delete, tags, bulk, stats, flush, keysByTag, invalidateTags(any), search (tag_any / tag_all via client-side set ops), basic HTTP fallback



### Installation## Build

```bash

1. **Build the extension:**phpize

   ```bash./configure --enable-tagcache

   ./scripts/configure_build.shmake -j$(sysctl -n hw.ncpu 2>/dev/null || nproc)

   ./scripts/build_with_serializers.shmake install

   ```# add extension=tagcache.so to php.ini

```

2. **Test the installation:**

   ```bash## Quick Usage

   php -d extension=./modules/tagcache.so -m | grep tagcache```php

   ```$client = tagcache_create([

  'mode' => 'tcp', // tcp|http|auto

3. **Run examples:**  'host' => '127.0.0.1',

   ```bash  'port' => 1984,

   php -d extension=./modules/tagcache.so examples/basic_usage.php  'http_base' => 'http://127.0.0.1:8080',

   ```  'timeout_ms' => 5000,

  'pool_size' => 8,

## 📁 Directory Structure]);



```tagcache_put($client, 'user:1', ['name' => 'Alice'], ['users'], 60000);

php-ext/$value = tagcache_get($client, 'user:1');

├── src/                    # Extension source code$stats = tagcache_stats($client);

│   ├── tagcache.c         # Main implementation```

│   └── tagcache.h         # Header definitions

├── examples/              # Usage examples and tutorials## Object-Oriented API (Preferred)

│   ├── basic_usage.php    # Getting started guideThe `TagCache` class offers a cleaner, chainable interface while using the same native core.

│   ├── bulk_operations.php # High-performance examples

│   └── advanced_features.php # Advanced functionality```php

├── tests/                 # PHP extension tests (.phpt format)$c = TagCache::create([

├── benchmarks/           # Performance testing suite  'host' => '127.0.0.1',

├── scripts/              # Build and utility scripts  'port' => 1984,

├── docs/                 # Documentation and reports  'mode' => 'tcp', // tcp | http | auto (http/auto enhanced modes forthcoming)

└── modules/              # Built extension files (.so)  'pool_size' => 16,

```]);



## 🔧 Configuration Options$c->set('user:1', ['id'=>1,'name'=>'Jane'], ['users','hot'], 10_000);

var_dump($c->get('user:1'));

```php$c->mSet(['a'=>1,'b'=>true,'c'=>[1,2,3]]);

$client = tagcache_create([var_dump(array_keys($c->mGet(['a','b','c'])));

    'host' => '127.0.0.1',    // Server host

    'port' => 1984,           // Server port$c->set('order:1', 100, ['orders','priority']);

    'pool_size' => 32,        // Connection pool size$c->set('order:2', 200, ['orders']);

    'keep_alive' => true,     // Enable connection reuse$orders = $c->keysByTag('orders');

    'tcp_nodelay' => true,    // Disable Nagle's algorithm$any = $c->searchAny(['orders','priority']);

    'timeout' => 0.5,         // Connection timeout (seconds)$all = $c->searchAll(['orders','priority']);

    'serialize_format' => 'native'  // Serialization method

]);$stats = $c->stats();

```$c->flush();

```

## 📊 Performance Highlights

Destruction: `__destruct()` calls `close()` automatically; manual `$c->close()` frees sockets sooner.

- **Single Operations:** 60,000+ ops/sec

- **Bulk Operations:** 270,000+ ops/sec  ### Mapping (Procedural → OO)

- **Efficiency Gain:** 5-10x improvement with bulk operations| Procedural | OO Method |

- **Memory Efficient:** Optimized connection pooling and data handling|------------|-----------|

| `tagcache_put` | `$c->set()` |

## 🎯 Key Features| `tagcache_get` | `$c->get()` |

| `tagcache_delete` | `$c->delete()` |

### High Performance| `tagcache_invalidate_tag` | `$c->invalidateTag()` |

- Connection pooling for concurrent access| `tagcache_keys_by_tag` | `$c->keysByTag()` |

- Bulk operations for maximum throughput| `tagcache_bulk_put` | `$c->mSet()` |

- TCP optimizations (keep-alive, nodelay)| `tagcache_bulk_get` | `$c->mGet()` |

- Native PHP serialization support| `tagcache_stats` | `$c->stats()` |

| `tagcache_flush` | `$c->flush()` |

### Flexible Data Management| `tagcache_search_any` | `$c->searchAny()` |

- Tag-based data organization| `tagcache_search_all` | `$c->searchAll()` |

- TTL (Time-To-Live) support| `tagcache_close` | `$c->close()` |

- Hierarchical tagging systems

- Bulk invalidation by tags## Serialization Semantics

Scalars (string/int/float) stored verbatim; booleans/null as markers: `__TC_TRUE__`, `__TC_FALSE__`, `__TC_NULL__`.

### Production ReadyComplex (arrays / objects) are `serialize()`d then base64 encoded with prefix `__TC_SERIALIZED__`.

- Thread-safe implementationThis mirrors the userland SDK so values are interchangeable.

- Comprehensive error handling

- Memory usage optimization## Planned Enhancements

- Extensive test coverage- Hybrid HTTP mode for search/stats when latency vs payload tradeoff favors HTTP.

- Optional igbinary/msgpack detection for more compact complex value encoding.

## 🚀 Usage Examples- Pipelined multi-GET / multi-SET to cut syscalls further.

- Pluggable compression for large (>32KB) payloads.

### Basic Operations

```php## Functions

$client = tagcache_create(['host' => '127.0.0.1', 'port' => 1984]);| Function | Description |

|----------|-------------|

// Store data with tags and TTL| `tagcache_create(array $options): resource` | Create client handle |

tagcache_put($client, 'user:123', $user_data, ['users', 'active'], 3600);| `tagcache_put($h, string $key, mixed $value, array $tags=[], ?int $ttl_ms=NULL): bool` | Store value |

| `tagcache_get($h, string $key): mixed` | Get raw value or null |

// Retrieve data| `tagcache_delete($h, string $key): bool` | Delete key |

$user = tagcache_get($client, 'user:123');| `tagcache_invalidate_tag($h, string $tag): int` | Invalidate by tag |

| `tagcache_keys_by_tag($h, string $tag): array` | List keys for tag |

// Invalidate by tag| `tagcache_bulk_get($h, array $keys): array` | Bulk get (missing omitted) |

tagcache_invalidate_tag($client, 'users');| `tagcache_bulk_put($h, array $items, ?int $ttl_ms=NULL): int` | Bulk put associative key=>value |

| `tagcache_stats($h): array` | Stats |

tagcache_close($client);| `tagcache_flush($h): int` | Flush |

```| `tagcache_search_any($h, array $tags): array` | tag_any search via union |

| `tagcache_search_all($h, array $tags): array` | tag_all search via intersection |

### Bulk Operations| `tagcache_close($h): void` | Free resources |

```php

// High-performance bulk operationsHTTP fallback automatically used for unsupported operations when `mode=auto`.

$data = ['key1' => 'value1', 'key2' => 'value2', /* ... */];

## Tests

// Bulk storePHPT style tests live in `tests/`. Run:

tagcache_bulk_put($client, $data, 3600);```bash

php run-tests.php -p $(which php) -q

// Bulk retrieve```

$results = tagcache_bulk_get($client, array_keys($data));

```## Bench

```bash

### Advanced Configurationphp bench/bench.php --host 127.0.0.1 --port 1984 --mode get --conns 4 --duration 10

```php```

// Optimized for high-concurrency applications

$client = tagcache_create([## Status

    'host' => '127.0.0.1',Experimental. API may change. Designed to be vendored separately (ignored in main repo).

    'port' => 1984,
    'pool_size' => 64,        // Large connection pool
    'keep_alive' => true,     // Connection reuse
    'tcp_nodelay' => true,    // Low latency
    'timeout' => 0.3          // Fast timeouts
]);
```

## 🔬 Testing and Benchmarking

### Run Tests
```bash
php scripts/run-tests.php
```

### Performance Benchmarks
```bash
# Basic benchmark
php -d extension=./modules/tagcache.so benchmarks/quick.php

# Comprehensive performance analysis
php -d extension=./modules/tagcache.so benchmarks/tcp_bottleneck_analysis.php

# Stress testing
php -d extension=./modules/tagcache.so benchmarks/optimized_stress_test.php
```

## 📚 Documentation

- **[Examples](examples/README.md)** - Comprehensive usage examples
- **[Performance Analysis](docs/PERFORMANCE_ANALYSIS_REPORT.md)** - Detailed performance metrics
- **[Thread Safety](docs/THREAD_SAFETY_FIXES.md)** - Thread safety implementation details
- **[Serialization](docs/SERIALIZATION_STATUS.md)** - Serialization format support

## 🛠️ Development

### Build from Source
```bash
# Configure build environment
./scripts/configure_build.sh

# Build with optional serializers
./scripts/build_with_serializers.sh

# Verify build
./scripts/verify_optimizations.sh
```

### Contributing
1. Follow the existing code style
2. Add tests for new features
3. Update documentation
4. Run performance benchmarks

## 📈 Performance Tuning

### Optimal Settings
- **Connection Pool:** 16-64 connections for high concurrency
- **Bulk Operations:** Use for > 10 items
- **Keep-Alive:** Always enable for production
- **TCP NoDelay:** Enable for low-latency requirements

### Monitoring
- Memory usage: Monitor with `memory_get_usage()`
- Connection pooling: Adjust `pool_size` based on load
- TTL strategy: Use appropriate expiration times

## 🐛 Troubleshooting

### Common Issues

**Extension not loading:**
```bash
# Check PHP configuration
php --ini

# Verify extension path
php -d extension=./modules/tagcache.so -m | grep tagcache
```

**Connection failures:**
- Ensure TagCache server is running
- Check host/port configuration
- Verify firewall settings

**Performance issues:**
- Use bulk operations when possible
- Enable connection pooling
- Adjust timeout values
- Monitor memory usage

## 📄 License

[License details - match your project's license]

## 🤝 Support

- **Documentation:** See `docs/` directory
- **Examples:** See `examples/` directory  
- **Issues:** [GitHub Issues]
- **Performance:** Run benchmarks in `benchmarks/`

---

**Built for high-performance PHP applications requiring fast, reliable caching with advanced data organization capabilities.**