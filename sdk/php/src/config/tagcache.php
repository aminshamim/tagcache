<?php

if (!function_exists('env')) {
    /**
     * Simple env() function fallback for non-Laravel contexts
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed {
        $value = $_ENV[$key] ?? getenv($key);
        return $value !== false ? $value : $default;
    }
}

return [
    'mode' => env('TAGCACHE_MODE', 'tcp'),
    'http' => [
        'base_url' => env('TAGCACHE_HTTP_URL', 'http://127.0.0.1:8080'),
        'timeout_ms' => env('TAGCACHE_HTTP_TIMEOUT_MS', 5000),
    ],
    'tcp' => [
        'host' => env('TAGCACHE_TCP_HOST', '127.0.0.1'),
        'port' => env('TAGCACHE_TCP_PORT', 1984),
        'timeout_ms' => env('TAGCACHE_TCP_TIMEOUT_MS', 2000),
        'pool_size' => env('TAGCACHE_TCP_POOL', 4),
    ],
    'auth' => [
        'token' => env('TAGCACHE_TOKEN', null),
    ],
    'cache' => [
        'default_ttl_ms' => env('TAGCACHE_DEFAULT_TTL_MS', 60000 * 60),
        'serializer' => env('TAGCACHE_SERIALIZER', 'native'), // 'native', 'json', 'igbinary', 'msgpack'
        'auto_serialize' => env('TAGCACHE_AUTO_SERIALIZE', true),
    ],
];
