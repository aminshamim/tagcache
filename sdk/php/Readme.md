# TagCache PHP SDK
TagCache PHP SDK documentation. See README.md for detailed usage.
High-performance PHP SDK for TagCache with HTTP and TCP transports.

- PHP 8.1+
- PSR-4 autoloading
- Framework-friendly (Laravel, Symfony, CakePHP, CI, Laminas)
- Token auth, timeouts

## Install

composer require tagcache/sdk

## Quickstart

```php
use TagCache\SDK\Client;
use TagCache\SDK\Config;

$client = new Client(Config::fromEnv([
  'mode' => 'http',
  'http' => [
    'base_url' => getenv('TAGCACHE_HTTP_URL') ?: 'http://127.0.0.1:8080',
    'timeout_ms' => 5000
  ],
  'auth' => [ 'token' => getenv('TAGCACHE_TOKEN') ]
]));

$client->put('user:42', ['name' => 'Ada', 'age' => 31], 60000, ['user','profile']);
$item = $client->get('user:42');
```

## Configuration

- mode: http | tcp | auto
- http.base_url (string)
- http.timeout_ms (int)
- tcp.host (string)
- tcp.port (int)
- tcp.timeout_ms (int)
- tcp.pool_size (int)
- auth.token (string)

## API

- put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): void
- get(string $key): ?Item
- delete(string $key): bool
- invalidateKeys(array $keys): int
- invalidateTags(array $tags, string $mode = 'any'): int
- keysByTag(string $tag, ?int $limit = null): Item[]
- keysByTagsAny(array $tags, ?int $limit = null): Item[]
- keysByTagsAll(array $tags, ?int $limit = null): Item[]
- bulkGet(array $keys): array
- bulkDelete(array $keys): int
- search(array $params): array
- stats(): array
- list(int $limit = 100): array
- getOrSet(string $key, callable $producer, ?int $ttlMs = null, array $tags = []): Item

## Frameworks

See examples/ for wiring in Laravel and Symfony. Service providers and bundles can register Client as a singleton with Config::fromEnv().

## Performance

- Prefer TCP in production for low latency and throughput.
- HTTP bulk endpoints supported: /keys/bulk/get, /keys/bulk/delete.
- TCP transport uses connection pooling and simple framed line protocol.
- Timeouts default to 2-5s; tune based on environment.

## Framework integration

- Laravel: ServiceProvider + Facade (coming next), bind Client as singleton from env.
- Symfony: Bundle and DI config (coming next).
- CakePHP/CodeIgniter/Laminas: config providers (coming next).

## License

MIT
