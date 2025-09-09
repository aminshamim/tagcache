<?php

namespace TagCache\SDK;

final class Config
{
    public readonly string $mode; // http|tcp|auto
    public readonly array $http;  // base_url, timeout_ms
    public readonly array $tcp;   // host, port, timeout_ms, pool_size
    public readonly array $auth;  // token, username, password

    public function __construct(array $options)
    {
        $this->mode = $options['mode'] ?? 'http';
        $this->http = $options['http'] ?? [];
        $this->tcp  = $options['tcp']  ?? [];
        $this->auth = $options['auth'] ?? [];
    }

    public static function fromEnv(array $overrides = []): self
    {
        $opt = $overrides;
        $opt['http']['base_url'] = $opt['http']['base_url'] ?? getenv('TAGCACHE_HTTP_URL') ?: 'http://127.0.0.1:8080';
        $opt['http']['timeout_ms'] = (int)($opt['http']['timeout_ms'] ?? getenv('TAGCACHE_HTTP_TIMEOUT_MS') ?: 5000);
        $opt['auth']['token'] = $opt['auth']['token'] ?? getenv('TAGCACHE_TOKEN') ?: '';
        return new self($opt);
    }
}
