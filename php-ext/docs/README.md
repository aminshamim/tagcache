# TagCache PHP Native Extension (Experimental)

High-performance, low-latency native PHP 8.4 extension for TagCache. Provides direct TCP protocol access plus lightweight HTTP fallback without per-call userland overhead.

## Goals
- Minimize latency vs userland SDK
- Persistent TCP connection pooling in C
- Zero-copy string handling where possible
- Automatic (de)serialization markers compatible with SDK (`__TC_*` scheme)
- Feature coverage: put/get/delete, tags, bulk, stats, flush, keysByTag, invalidateTags(any), search (tag_any / tag_all via client-side set ops), basic HTTP fallback

## Build
```bash
phpize
./configure --enable-tagcache
make -j$(sysctl -n hw.ncpu 2>/dev/null || nproc)
make install
# add extension=tagcache.so to php.ini
```

## Quick Usage
```php
$client = tagcache_create([
  'mode' => 'tcp', // tcp|http|auto
  'host' => '127.0.0.1',
  'port' => 1984,
  'http_base' => 'http://127.0.0.1:8080',
  'timeout_ms' => 5000,
  'pool_size' => 8,
]);

tagcache_put($client, 'user:1', ['name' => 'Alice'], ['users'], 60000);
$value = tagcache_get($client, 'user:1');
$stats = tagcache_stats($client);
```

## Object-Oriented API (Preferred)
The `TagCache` class offers a cleaner, chainable interface while using the same native core.

```php
$c = TagCache::create([
  'host' => '127.0.0.1',
  'port' => 1984,
  'mode' => 'tcp', // tcp | http | auto (http/auto enhanced modes forthcoming)
  'pool_size' => 16,
]);

$c->set('user:1', ['id'=>1,'name'=>'Jane'], ['users','hot'], 10_000);
var_dump($c->get('user:1'));
$c->mSet(['a'=>1,'b'=>true,'c'=>[1,2,3]]);
var_dump(array_keys($c->mGet(['a','b','c'])));

$c->set('order:1', 100, ['orders','priority']);
$c->set('order:2', 200, ['orders']);
$orders = $c->keysByTag('orders');
$any = $c->searchAny(['orders','priority']);
$all = $c->searchAll(['orders','priority']);

$stats = $c->stats();
$c->flush();
```

Destruction: `__destruct()` calls `close()` automatically; manual `$c->close()` frees sockets sooner.

### Mapping (Procedural → OO)
| Procedural | OO Method |
|------------|-----------|
| `tagcache_put` | `$c->set()` |
| `tagcache_get` | `$c->get()` |
| `tagcache_delete` | `$c->delete()` |
| `tagcache_invalidate_tag` | `$c->invalidateTag()` |
| `tagcache_keys_by_tag` | `$c->keysByTag()` |
| `tagcache_bulk_put` | `$c->mSet()` |
| `tagcache_bulk_get` | `$c->mGet()` |
| `tagcache_stats` | `$c->stats()` |
| `tagcache_flush` | `$c->flush()` |
| `tagcache_search_any` | `$c->searchAny()` |
| `tagcache_search_all` | `$c->searchAll()` |
| `tagcache_close` | `$c->close()` |

## Serialization Semantics
Scalars (string/int/float) stored verbatim; booleans/null as markers: `__TC_TRUE__`, `__TC_FALSE__`, `__TC_NULL__`.
Complex (arrays / objects) are `serialize()`d then base64 encoded with prefix `__TC_SERIALIZED__`.
This mirrors the userland SDK so values are interchangeable.

## Planned Enhancements
- Hybrid HTTP mode for search/stats when latency vs payload tradeoff favors HTTP.
- Optional igbinary/msgpack detection for more compact complex value encoding.
- Pipelined multi-GET / multi-SET to cut syscalls further.
- Pluggable compression for large (>32KB) payloads.

## Functions
| Function | Description |
|----------|-------------|
| `tagcache_create(array $options): resource` | Create client handle |
| `tagcache_put($h, string $key, mixed $value, array $tags=[], ?int $ttl_ms=NULL): bool` | Store value |
| `tagcache_get($h, string $key): mixed` | Get raw value or null |
| `tagcache_delete($h, string $key): bool` | Delete key |
| `tagcache_invalidate_tag($h, string $tag): int` | Invalidate by tag |
| `tagcache_keys_by_tag($h, string $tag): array` | List keys for tag |
| `tagcache_bulk_get($h, array $keys): array` | Bulk get (missing omitted) |
| `tagcache_bulk_put($h, array $items, ?int $ttl_ms=NULL): int` | Bulk put associative key=>value |
| `tagcache_stats($h): array` | Stats |
| `tagcache_flush($h): int` | Flush |
| `tagcache_search_any($h, array $tags): array` | tag_any search via union |
| `tagcache_search_all($h, array $tags): array` | tag_all search via intersection |
| `tagcache_close($h): void` | Free resources |

HTTP fallback automatically used for unsupported operations when `mode=auto`.

## Tests
PHPT style tests live in `tests/`. Run:
```bash
php run-tests.php -p $(which php) -q
```

## Bench
```bash
php bench/bench.php --host 127.0.0.1 --port 1984 --mode get --conns 4 --duration 10
```

## Status
Experimental. API may change. Designed to be vendored separately (ignored in main repo).
