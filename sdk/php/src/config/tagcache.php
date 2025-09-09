<?php

return [
    'mode' => env('TAGCACHE_MODE', 'http'),
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
];
