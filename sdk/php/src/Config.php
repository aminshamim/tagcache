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
        ];
        
        // TCP configuration optimized for high performance (matches tagcache.sh defaults)
        $tcpBase = $options['tcp'] ?? [];
        $this->tcp = [
            'host' => $tcpBase['host'] ?? $options['host'] ?? '127.0.0.1',
            'port' => (int)($tcpBase['port'] ?? $options['port'] ?? 1984), // From tagcache.sh
            'timeout_ms' => (int)($tcpBase['timeout_ms'] ?? $options['timeout_ms'] ?? 2000),
            'pool_size' => (int)($tcpBase['pool_size'] ?? $options['pool_size'] ?? 8),
            'connect_timeout_ms' => (int)($tcpBase['connect_timeout_ms'] ?? 1000),
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
            ],
            'retry_attempts' => (int)$getEnvValue('TAGCACHE_RETRY_ATTEMPTS', 3),
            'retry_delay_ms' => (int)$getEnvValue('TAGCACHE_RETRY_DELAY', 100),
        ];
        
        return new self(array_merge($options, $overrides));
    }
}
