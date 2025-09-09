<?php declare(strict_types=1);

namespace TagCache;

use RuntimeException;

/**
 * High-performance configuration for TagCache SDK
 * Optimized for production use with credential auto-loading and connection pooling
 */
final class Config
{
    public readonly string $mode;
    public readonly array $http;
    public readonly array $tcp;
    public readonly array $auth;
    
    private static ?array $credentialCache = null;

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
            'username' => $authBase['username'] ?? $options['username'] ?? $credentials['username'] ?? '',
            'password' => $authBase['password'] ?? $options['password'] ?? $credentials['password'] ?? '',
            'auto_login' => $authBase['auto_login'] ?? true,
        ];
    }

    /**
     * Load credentials from credential.txt in project root
     */
    private function loadCredentials(): array
    {
        if (self::$credentialCache !== null) {
            return self::$credentialCache;
        }

        $credentialPath = $this->findCredentialFile();
        if (!$credentialPath || !is_readable($credentialPath)) {
            return self::$credentialCache = ['username' => '', 'password' => ''];
        }

        try {
            $content = file_get_contents($credentialPath);
            $credentials = [];
            
            foreach (explode("\n", $content) as $line) {
                $line = trim($line);
                if (empty($line) || !str_contains($line, '=')) continue;
                
                [$key, $value] = explode('=', $line, 2);
                $credentials[trim($key)] = trim($value);
            }
            
            return self::$credentialCache = [
                'username' => $credentials['username'] ?? '',
                'password' => $credentials['password'] ?? '',
            ];
        } catch (\Throwable $e) {
            return self::$credentialCache = ['username' => '', 'password' => ''];
        }
    }

    /**
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

    public static function fromEnv(array $overrides = []): self
    {
        $opt = $overrides;
        
        // HTTP configuration from environment
        $opt['http']['base_url'] = $opt['http']['base_url'] ?? getenv('TAGCACHE_HTTP_URL') ?: 'http://localhost:8080';
        $opt['http']['timeout_ms'] = (int)($opt['http']['timeout_ms'] ?? getenv('TAGCACHE_HTTP_TIMEOUT_MS') ?: 5000);
        
        // TCP configuration from environment  
        $opt['tcp']['host'] = $opt['tcp']['host'] ?? getenv('TAGCACHE_TCP_HOST') ?: '127.0.0.1';
        $opt['tcp']['port'] = (int)($opt['tcp']['port'] ?? getenv('TAGCACHE_TCP_PORT') ?: 1984);
        
        // Auth configuration from environment
        $opt['auth']['token'] = $opt['auth']['token'] ?? getenv('TAGCACHE_TOKEN') ?: '';
        $opt['auth']['username'] = $opt['auth']['username'] ?? getenv('TAGCACHE_USERNAME') ?: '';
        $opt['auth']['password'] = $opt['auth']['password'] ?? getenv('TAGCACHE_PASSWORD') ?: '';
        
        return new self($opt);
    }
}
