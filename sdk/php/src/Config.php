<?php declare(strict_types=1);

namespace TagCache;

final class Config
{
    public readonly string $mode; // http|tcp|auto
    public array $http;  // base_url, timeout_ms
    public array $tcp;   // host, port, timeout_ms, pool_size
    public array $auth;  // token, username, password

    public function __construct(array $options)
    {
    $this->mode = $options['mode'] ?? 'http';
    // Allow flexibility: accept http config either at top-level or in 'http' key
    $this->http = $options['http'] ?? [];
    if (isset($options['base_url'])) { $this->http['base_url'] = $this->http['base_url'] ?? $options['base_url']; }
    if (isset($options['timeout_ms'])) { $this->http['timeout_ms'] = $this->http['timeout_ms'] ?? $options['timeout_ms']; }
    if (isset($options['max_retries'])) { $this->http['max_retries'] = $this->http['max_retries'] ?? $options['max_retries']; }
    if (isset($options['retry_delay_ms'])) { $this->http['retry_delay_ms'] = $this->http['retry_delay_ms'] ?? $options['retry_delay_ms']; }

    $this->tcp  = $options['tcp']  ?? [];
    if (isset($options['host'])) { $this->tcp['host'] = $this->tcp['host'] ?? $options['host']; }
    if (isset($options['port'])) { $this->tcp['port'] = $this->tcp['port'] ?? $options['port']; }
    if (isset($options['timeout_ms'])) { $this->tcp['timeout_ms'] = $this->tcp['timeout_ms'] ?? $options['timeout_ms']; }
    if (isset($options['pool_size'])) { $this->tcp['pool_size'] = $this->tcp['pool_size'] ?? $options['pool_size']; }

    $this->auth = $options['auth'] ?? [];
    if (isset($options['username'])) { $this->auth['username'] = $this->auth['username'] ?? $options['username']; }
    if (isset($options['password'])) { $this->auth['password'] = $this->auth['password'] ?? $options['password']; }
    }

    public static function fromEnv(array $overrides = []): self
    {
        $opt = $overrides;
        $opt['http']['base_url'] = $opt['http']['base_url'] ?? getenv('TAGCACHE_HTTP_URL') ?: 'http://localhost:8080';
        $opt['http']['timeout_ms'] = (int)($opt['http']['timeout_ms'] ?? getenv('TAGCACHE_HTTP_TIMEOUT_MS') ?: 5000);
        $opt['auth']['token'] = $opt['auth']['token'] ?? getenv('TAGCACHE_TOKEN') ?: '';
        return new self($opt);
    }
}
