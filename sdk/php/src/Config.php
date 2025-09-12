<?php declare(strict_types=1);

/**
 * TagCache PHP SDK - Configuration management
 * 
 * @author Md. Aminul Islam Sarker <aminshamim@gmail.com>
 * @link https://github.com/aminshamim/tagcache
 * @link https://www.linkedin.com/in/aminshamim/
 */

namespace TagCache;

use RuntimeException;

/**
 * High-performance configuration for TagCache SD        $getEnvValue = function(string $key, mixed $default = null): mixed {
            return $_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : $default);
        };
        
        $options = [
            'mode' => $getEnvValue('TAGCACHE_MODE', 'http'),
            'http' => [
                'base_url' => $getEnvValue('TAGCACHE_HTTP_URL', 'http://localhost:8080'),
                'timeout_ms' => (int)$getEnvValue('TAGCACHE_HTTP_TIMEOUT', 5000),
            ],
            'tcp' => [
                'host' => $getEnvValue('TAGCACHE_TCP_HOST', 'localhost'), 
                'port' => (int)$getEnvValue('TAGCACHE_TCP_PORT', 1984),
                'timeout_ms' => (int)$getEnvValue('TAGCACHE_TCP_TIMEOUT', 5000),
                'pool_size' => (int)$getEnvValue('TAGCACHE_TCP_POOL_SIZE', 5),
            ],
            'auth' => [
                'username' => $getEnvValue('TAGCACHE_USERNAME', ''),
                'password' => $getEnvValue('TAGCACHE_PASSWORD', ''),
                'token' => $getEnvValue('TAGCACHE_TOKEN', null),
            ],r production use with credential auto-loading and connection pooling
 */
final class Config
{
    public readonly string $mode;
    /** @var array<string, mixed> */
    public readonly array $http;
    /** @var array<string, mixed> */
    public readonly array $tcp;
    /** @var array<string, mixed> */
    public readonly array $auth;
    /** @var array<string, mixed> */
    public readonly array $cache;
    
    /** @var array<string, mixed>|null */
    private static ?array $credentialCache = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        // Load credentials from credential.txt if available
        $credentials = $this->loadCredentials();
        
        // Mode selection with intelligent auto-detection
        $this->mode = $options['mode'] ?? 'auto';
        
        // HTTP configuration with production defaults
        $httpBase = $options['http'] ?? [];
        $this->http = [
            'base_url' => $httpBase['base_url'] ?? $options['base_url'] ?? 'http://localhost:8080',
            'timeout_ms' => (int)($httpBase['timeout_ms'] ?? $options['timeout_ms'] ?? 5000),
            'max_retries' => (int)($httpBase['max_retries'] ?? $options['max_retries'] ?? 3),
            'retry_delay_ms' => (int)($httpBase['retry_delay_ms'] ?? $options['retry_delay_ms'] ?? 200),
            'connection_pool_size' => (int)($httpBase['connection_pool_size'] ?? 10),
            'keep_alive' => $httpBase['keep_alive'] ?? true,
            'user_agent' => $httpBase['user_agent'] ?? 'TagCache-PHP-SDK/1.0',
            // Serialization configuration
            'serializer' => $httpBase['serializer'] ?? $options['serializer'] ?? 'igbinary', // igbinary > msgpack > native
            'auto_serialize' => $httpBase['auto_serialize'] ?? $options['auto_serialize'] ?? true,
        ];
        
        // TCP configuration optimized for high performance with enhanced defaults
        $tcpBase = $options['tcp'] ?? [];
        $this->tcp = [
            'host' => $tcpBase['host'] ?? $options['host'] ?? '127.0.0.1',
            'port' => (int)($tcpBase['port'] ?? $options['port'] ?? 1984), // From tagcache.sh
            'timeout_ms' => (int)($tcpBase['timeout_ms'] ?? $options['timeout_ms'] ?? 5000), // Enhanced default
            'connect_timeout_ms' => (int)($tcpBase['connect_timeout_ms'] ?? 3000), // Separate connect timeout
            'pool_size' => (int)($tcpBase['pool_size'] ?? $options['pool_size'] ?? 8), // Enhanced pool size
            'max_retries' => (int)($tcpBase['max_retries'] ?? 3), // Retry logic
            'retry_delay_ms' => (int)($tcpBase['retry_delay_ms'] ?? 100), // Retry delay
            'tcp_nodelay' => $tcpBase['tcp_nodelay'] ?? true, // Disable Nagle algorithm
            'keep_alive' => $tcpBase['keep_alive'] ?? true, // TCP keep-alive
            'keep_alive_interval' => (int)($tcpBase['keep_alive_interval'] ?? 30), // Keep-alive interval
            'persistent' => $tcpBase['persistent'] ?? true,
        ];
        
        // Authentication with credential.txt integration
        $authBase = $options['auth'] ?? [];
        $this->auth = [
            'token' => $authBase['token'] ?? $options['token'] ?? '',
            'username' => (!empty($authBase['username'])) ? $authBase['username'] 
                        : ((!empty($options['username'])) ? $options['username'] 
                        : ($credentials['username'] ?? '')),
            'password' => (!empty($authBase['password'])) ? $authBase['password'] 
                        : ((!empty($options['password'])) ? $options['password'] 
                        : ($credentials['password'] ?? '')),
            'auto_login' => $authBase['auto_login'] ?? true,
        ];
        
        // Cache configuration with default TTL
        $cacheBase = $options['cache'] ?? [];
        $defaultTtl = $cacheBase['default_ttl_ms'] ?? $options['default_ttl_ms'] ?? null;
        $this->cache = [
            'default_ttl_ms' => $defaultTtl !== null ? (int)$defaultTtl : null,
            'max_ttl_ms' => (int)($cacheBase['max_ttl_ms'] ?? $options['max_ttl_ms'] ?? 86400000), // 24 hours max
        ];
    }

    /**
     * Load credentials from credential.txt in project root
     */
    /**
     * @return array<string, mixed>
     */
    private function loadCredentials(): array
    {
        if (self::$credentialCache !== null) {
            return self::$credentialCache;
        }
        
        $credFile = $this->findCredentialFile();
        if ($credFile === null) {
            return self::$credentialCache = [];
        }
        
        $content = file_get_contents($credFile);
        if ($content === false) {
            return self::$credentialCache = [];
        }
        
        $credentials = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $credentials[trim($key)] = trim($value);
            }
        }
        return self::$credentialCache = $credentials;
    }    /**
     * Find credential.txt in project root or parent directories
     */
    private function findCredentialFile(): ?string
    {
        $paths = [
            __DIR__ . '/../../../../credential.txt',  // From sdk/php/src
            __DIR__ . '/../../../credential.txt',     // Alternative
            getcwd() . '/credential.txt',             // Current working directory
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function fromEnv(array $overrides = []): self
    {
        $getEnvValue = function(string $key, mixed $default = null): mixed {
            return $_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : $default);
        };
        
        $options = [
            'mode' => $getEnvValue('TAGCACHE_MODE', 'http'),
            'http' => [
                'base_url' => $getEnvValue('TAGCACHE_HTTP_URL', 'http://localhost:8080'),
                'timeout_ms' => (int)$getEnvValue('TAGCACHE_HTTP_TIMEOUT', 5000),
                'serializer' => $getEnvValue('TAGCACHE_SERIALIZER', 'igbinary'),
                'auto_serialize' => filter_var($getEnvValue('TAGCACHE_AUTO_SERIALIZE', true), FILTER_VALIDATE_BOOLEAN),
            ],
            'tcp' => [
                'host' => $getEnvValue('TAGCACHE_TCP_HOST', 'localhost'), 
                'port' => (int)$getEnvValue('TAGCACHE_TCP_PORT', 1984),
                'timeout_ms' => (int)$getEnvValue('TAGCACHE_TCP_TIMEOUT_MS', 5000),
                'connect_timeout_ms' => (int)$getEnvValue('TAGCACHE_TCP_CONNECT_TIMEOUT_MS', 3000),
                'pool_size' => (int)$getEnvValue('TAGCACHE_TCP_POOL_SIZE', 8),
                'max_retries' => (int)$getEnvValue('TAGCACHE_TCP_MAX_RETRIES', 3),
                'retry_delay_ms' => (int)$getEnvValue('TAGCACHE_TCP_RETRY_DELAY_MS', 100),
                'tcp_nodelay' => filter_var($getEnvValue('TAGCACHE_TCP_NODELAY', 'true'), FILTER_VALIDATE_BOOLEAN),
                'keep_alive' => filter_var($getEnvValue('TAGCACHE_TCP_KEEPALIVE', 'true'), FILTER_VALIDATE_BOOLEAN),
                'keep_alive_interval' => (int)$getEnvValue('TAGCACHE_TCP_KEEPALIVE_INTERVAL', 30),
            ],
            'auth' => [
                'username' => $getEnvValue('TAGCACHE_USERNAME', ''),
                'password' => $getEnvValue('TAGCACHE_PASSWORD', ''),
                'token' => $getEnvValue('TAGCACHE_TOKEN', null),
            ],
            'cache' => [
                'default_ttl_ms' => $getEnvValue('TAGCACHE_DEFAULT_TTL_MS', null) ? (int)$getEnvValue('TAGCACHE_DEFAULT_TTL_MS') : null,
                'max_ttl_ms' => (int)$getEnvValue('TAGCACHE_MAX_TTL_MS', 86400000),
            ],
            'retry_attempts' => (int)$getEnvValue('TAGCACHE_RETRY_ATTEMPTS', 3),
            'retry_delay_ms' => (int)$getEnvValue('TAGCACHE_RETRY_DELAY', 100),
        ];
        
        return new self(array_merge($options, $overrides));
    }
    
    /**
     * Get the default TTL in milliseconds from configuration
     */
    public function getDefaultTtlMs(): ?int
    {
        return $this->cache['default_ttl_ms'] ?? null;
    }
}
