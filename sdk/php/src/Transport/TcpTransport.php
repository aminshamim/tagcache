<?php

namespace TagCache\Transport;

use TagCache\Config;
use TagCache\Exceptions\ApiException;
use TagCache\Exceptions\AuthenticationException;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\ConfigurationException;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\TimeoutException;

final class TcpTransport implements TransportInterface
{
    private string $host;
    private int $port;
    private int $timeoutMs;
    private int $connectTimeoutMs;
    private int $poolSize;
    private int $maxRetries;
    private int $retryDelayMs;
    private bool $tcpNoDelay;
    private bool $keepAlive;
    private int $keepAliveInterval;
    
    /** @var array<int, array{resource: resource, created: float, last_used: float, healthy: bool}> */
    private array $pool = [];
    private int $rr = 0;
    private Config $config;
    
    // Connection health tracking
    private int $connectionFailures = 0;
    private float $lastFailureTime = 0.0;
    private const MAX_FAILURES_BEFORE_RESET = 3;
    private const FAILURE_RESET_INTERVAL = 30.0; // seconds

    public function __construct(Config $config)
    {
        $this->config = $config;
        $tcp = $config->tcp + [
            'host' => '127.0.0.1',
            'port' => 1984,
            'timeout_ms' => 5000,
            'connect_timeout_ms' => 3000,
            'pool_size' => 8,
            'max_retries' => 3,
            'retry_delay_ms' => 100,
            'tcp_nodelay' => true,
            'keep_alive' => true,
            'keep_alive_interval' => 30
        ];
        
        $this->host = (string)$tcp['host'];
        $this->port = (int)$tcp['port'];
        $this->timeoutMs = max(1000, (int)$tcp['timeout_ms']);
        $this->connectTimeoutMs = max(1000, (int)$tcp['connect_timeout_ms']);
        $this->poolSize = max(1, min(20, (int)$tcp['pool_size']));
        $this->maxRetries = max(0, min(10, (int)$tcp['max_retries']));
        $this->retryDelayMs = max(10, (int)$tcp['retry_delay_ms']);
        $this->tcpNoDelay = (bool)$tcp['tcp_nodelay'];
        $this->keepAlive = (bool)$tcp['keep_alive'];
        $this->keepAliveInterval = max(1, (int)$tcp['keep_alive_interval']);
        
        // Validate serialization capability
        $this->validateSerializer();
    }
    
    /**
     * Validates that the configured serializer is available
     * @throws ConfigurationException
     */
    private function validateSerializer(): void
    {
        $serializer = $this->config->cache['serializer'] ?? 'native';
        
        if ($serializer === 'igbinary' && !extension_loaded('igbinary')) {
            throw new ConfigurationException('igbinary serializer configured but igbinary extension not loaded');
        }
        
        if ($serializer === 'msgpack' && !extension_loaded('msgpack')) {
            throw new ConfigurationException('msgpack serializer configured but msgpack extension not loaded');
        }
    }

    /**
     * Serialize value using unified marker scheme matching HttpTransport.
     * Uses markers:
     *  __TC_NULL__ / __TC_TRUE__ / __TC_FALSE__ for primitives
     *  raw numeric strings for ints/floats
     *  plain string unchanged
     *  __TC_SERIALIZED__<base64(serialized-by-configured-serializer)>
     */
    private function performSerialization(mixed $data): string
    {
        // Primitive fast paths identical to HttpTransport
        if (is_string($data)) return $data;
        if (is_int($data) || is_float($data)) return (string)$data;
        if (is_bool($data)) return $data ? '__TC_TRUE__' : '__TC_FALSE__';
        if ($data === null) return '__TC_NULL__';

        $serializer = $this->config->cache['serializer'] ?? 'native';

        // Choose serializer (igbinary/msgpack/native serialize)
        switch ($serializer) {
            case 'igbinary':
                if (function_exists('igbinary_serialize')) {
                    $serialized = call_user_func('igbinary_serialize', $data);
                } else {
                    $serialized = serialize($data);
                }
                break;
            case 'msgpack':
                if (function_exists('msgpack_pack')) {
                    $serialized = call_user_func('msgpack_pack', $data);
                } else {
                    $serialized = serialize($data);
                }
                break;
            case 'native':
            case 'auto':
            default:
                $serialized = serialize($data);
                break;
        }

        // Always base64 encode to keep TCP line safe (no tabs/newlines/binary)
        return '__TC_SERIALIZED__' . base64_encode($serialized);
    }

    /**
     * Deserialize value using the unified marker scheme (no legacy compatibility required).
     */
    private function performDeserialization(string $value): mixed
    {
        // Handle new markers
        if ($value === '__TC_NULL__') return null;
        if ($value === '__TC_TRUE__') return true;
        if ($value === '__TC_FALSE__') return false;

        if (str_starts_with($value, '__TC_SERIALIZED__')) {
            $b64 = substr($value, strlen('__TC_SERIALIZED__'));
            $decoded = base64_decode($b64, true);
            if ($decoded === false) return $value; // corrupt

            $serializer = $this->config->cache['serializer'] ?? 'native';
            switch ($serializer) {
                case 'igbinary':
                    return function_exists('igbinary_unserialize') ? call_user_func('igbinary_unserialize', $decoded) : unserialize($decoded);
                case 'msgpack':
                    return function_exists('msgpack_unpack') ? call_user_func('msgpack_unpack', $decoded) : unserialize($decoded);
                case 'native':
                case 'auto':
                default:
                    return unserialize($decoded);
            }
        }
        // Numeric detection (mirror HttpTransport)
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return $value; // treat as plain string
    }

    /**
     * Enhanced connection retrieval with health monitoring and recovery
     * @return resource
     * @throws ConnectionException
     */
    private function conn()
    {
        // Reset failure counter if enough time has passed
        if ($this->connectionFailures > 0 && (microtime(true) - $this->lastFailureTime) > self::FAILURE_RESET_INTERVAL) {
            $this->connectionFailures = 0;
        }
        
        // If too many failures, reset pool and try fresh connections
        if ($this->connectionFailures >= self::MAX_FAILURES_BEFORE_RESET) {
            $this->resetConnectionPool();
            $this->connectionFailures = 0;
        }
        
        // Clean up expired/unhealthy connections
        $this->cleanupPool();
        
        // Try to get a healthy connection from pool first
        $attempts = 0;
        while ($attempts < count($this->pool) && count($this->pool) > 0) {
            $this->rr = ($this->rr + 1) % count($this->pool);
            $connInfo = $this->pool[$this->rr];
            
            if ($this->isConnectionHealthy($connInfo)) {
                $connInfo['last_used'] = microtime(true);
                $this->pool[$this->rr] = $connInfo;
                return $connInfo['resource'];
            } else {
                // Remove unhealthy connection
                $this->closeConnection($connInfo['resource']);
                array_splice($this->pool, $this->rr, 1);
                if ($this->rr >= count($this->pool) && count($this->pool) > 0) {
                    $this->rr = 0;
                }
            }
            $attempts++;
        }
        
        // Need a new connection
        if (count($this->pool) < $this->poolSize) {
            $newConn = $this->createNewConnection();
            $connInfo = [
                'resource' => $newConn,
                'created' => microtime(true),
                'last_used' => microtime(true),
                'healthy' => true
            ];
            $this->pool[] = $connInfo;
            $this->rr = count($this->pool) - 1;
            return $newConn;
        }
        
        // Pool is full, replace oldest connection
        $oldestIndex = 0;
        $oldestTime = $this->pool[0]['created'];
        for ($i = 1; $i < count($this->pool); $i++) {
            if ($this->pool[$i]['created'] < $oldestTime) {
                $oldestIndex = $i;
                $oldestTime = $this->pool[$i]['created'];
            }
        }
        
        $this->closeConnection($this->pool[$oldestIndex]['resource']);
        $newConn = $this->createNewConnection();
        $this->pool[$oldestIndex] = [
            'resource' => $newConn,
            'created' => microtime(true),
            'last_used' => microtime(true),
            'healthy' => true
        ];
        $this->rr = $oldestIndex;
        return $newConn;
    }

    /**
     * Creates a new TCP connection with enhanced configuration
     * @return resource
     * @throws ConnectionException
     */
    private function createNewConnection()
    {
        $maxAttempts = $this->maxRetries + 1;
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $addr = sprintf('%s:%d', $this->host, $this->port);
                
                // Create enhanced context with TCP options
                $contextOptions = [
                    'socket' => [
                        'tcp_nodelay' => $this->tcpNoDelay,
                        'so_keepalive' => $this->keepAlive,
                    ]
                ];
                
                if ($this->keepAlive) {
                    $contextOptions['socket']['tcp_keepalive_time'] = $this->keepAliveInterval;
                    $contextOptions['socket']['tcp_keepalive_interval'] = max(1, intval($this->keepAliveInterval / 3));
                    $contextOptions['socket']['tcp_keepalive_probes'] = 3;
                }
                
                $ctx = stream_context_create($contextOptions);
                
                $sock = @stream_socket_client(
                    $addr, 
                    $errno, 
                    $errstr, 
                    $this->connectTimeoutMs / 1000, 
                    STREAM_CLIENT_CONNECT, 
                    $ctx
                );
                
                if (!$sock) {
                    throw new ConnectionException("TCP connect error: $errstr ($errno)");
                }
                
                // Set read/write timeouts
                $timeoutSec = intval($this->timeoutMs / 1000);
                $timeoutUsec = ($this->timeoutMs % 1000) * 1000;
                stream_set_timeout($sock, $timeoutSec, $timeoutUsec);
                
                // Set socket options for better performance
                if (function_exists('socket_import_stream')) {
                    $socket = socket_import_stream($sock);
                    if ($socket !== false) {
                        socket_set_option($socket, SOL_TCP, TCP_NODELAY, $this->tcpNoDelay ? 1 : 0);
                        if ($this->keepAlive) {
                            socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                        }
                    }
                }
                
                return $sock;
                
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->connectionFailures++;
                $this->lastFailureTime = microtime(true);
                
                if ($attempt < $maxAttempts) {
                    // Exponential backoff with jitter
                    $delay = $this->retryDelayMs * (2 ** ($attempt - 1));
                    $jitter = random_int(0, intval($delay * 0.1));
                    usleep(($delay + $jitter) * 1000);
                }
            }
        }
        
        throw new ConnectionException(
            sprintf('Failed to establish TCP connection after %d attempts. Last error: %s', 
                $maxAttempts, 
                $lastException ? $lastException->getMessage() : 'Unknown error'
            ), 
            0, 
            $lastException
        );
    }
    
    /**
     * Checks if a connection is healthy and usable
     */
    private function isConnectionHealthy(array $connInfo): bool
    {
        if (!$connInfo['healthy']) {
            return false;
        }
        
        $resource = $connInfo['resource'];
        
        // Check if resource is still valid
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            return false;
        }
        
        // Check connection metadata
        $meta = stream_get_meta_data($resource);
        if ($meta['eof'] || $meta['timed_out']) {
            return false;
        }
        
        // Check if connection is too old (optional aging)
        $maxAge = 300; // 5 minutes
        if ((microtime(true) - $connInfo['created']) > $maxAge) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Safely closes a connection
     */
    private function closeConnection($resource): void
    {
        if (is_resource($resource)) {
            @fclose($resource);
        }
    }
    
    /**
     * Cleans up expired or unhealthy connections from pool
     */
    private function cleanupPool(): void
    {
        $this->pool = array_values(array_filter($this->pool, function($connInfo) {
            if (!$this->isConnectionHealthy($connInfo)) {
                $this->closeConnection($connInfo['resource']);
                return false;
            }
            return true;
        }));
        
        // Reset round-robin counter if needed
        if ($this->rr >= count($this->pool) && count($this->pool) > 0) {
            $this->rr = 0;
        }
    }
    
    /**
     * Resets the entire connection pool
     */
    private function resetConnectionPool(): void
    {
        foreach ($this->pool as $connInfo) {
            $this->closeConnection($connInfo['resource']);
        }
        $this->pool = [];
        $this->rr = 0;
    }

    /**
     * Executes a command with enhanced error handling and retry logic
     * @throws ConnectionException|TimeoutException|ApiException
     */
    private function cmd(string $line): string
    {
        $maxAttempts = $this->maxRetries + 1;
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $sock = $this->conn();
                
                // Send command
                $command = $line . "\n";
                $written = @fwrite($sock, $command);
                if ($written === false) {
                    throw new ConnectionException('TCP write failed');
                }
                if ($written !== strlen($command)) {
                    throw new ConnectionException('TCP partial write: expected ' . strlen($command) . ', wrote ' . $written);
                }
                
                // Read response with timeout handling
                $resp = @fgets($sock);
                if ($resp === false) {
                    $meta = stream_get_meta_data($sock);
                    if ($meta['timed_out']) {
                        throw new TimeoutException('TCP read timeout after ' . $this->timeoutMs . 'ms');
                    }
                    throw new ConnectionException('TCP read failed');
                }
                
                // Mark connection as healthy since command succeeded
                $this->markConnectionHealthy($sock);
                
                return rtrim($resp, "\r\n");
                
            } catch (ConnectionException|TimeoutException $e) {
                $lastException = $e;
                $this->markConnectionUnhealthy($sock ?? null);
                
                // Don't retry authentication failures
                if ($e instanceof AuthenticationException) {
                    throw $e;
                }
                
                if ($attempt < $maxAttempts) {
                    // Exponential backoff with jitter
                    $delay = $this->retryDelayMs * (2 ** ($attempt - 1));
                    $jitter = random_int(0, intval($delay * 0.1));
                    usleep(($delay + $jitter) * 1000);
                }
            } catch (\Throwable $e) {
                // Convert other exceptions to ApiException
                throw new ApiException('TCP command failed: ' . $e->getMessage(), 0, $e);
            }
        }
        
        throw new ConnectionException(
            sprintf('TCP command failed after %d attempts. Last error: %s', 
                $maxAttempts, 
                $lastException ? $lastException->getMessage() : 'Unknown error'
            ), 
            0, 
            $lastException
        );
    }
    
    /**
     * Marks a connection as healthy in the pool
     */
    private function markConnectionHealthy($resource): void
    {
        foreach ($this->pool as $index => $connInfo) {
            if ($connInfo['resource'] === $resource) {
                $connInfo['healthy'] = true;
                $connInfo['last_used'] = microtime(true);
                $this->pool[$index] = $connInfo;
                break;
            }
        }
    }
    
    /**
     * Marks a connection as unhealthy in the pool
     */
    private function markConnectionUnhealthy($resource): void
    {
        if ($resource === null) return;
        
        foreach ($this->pool as $index => $connInfo) {
            if ($connInfo['resource'] === $resource) {
                $connInfo['healthy'] = false;
                $this->pool[$index] = $connInfo;
                break;
            }
        }
    }

    /**
     * Store a key-value pair with tags and TTL using enhanced serialization
     * @param string[] $tags
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool
    {
        // Apply default TTL if not specified
        if ($ttlMs === null) {
            $ttlMs = $this->config->getDefaultTtlMs();
        }
        
        // Serialize the value using the configured method
        $serializedValue = $this->performSerialization($value);
        
        // Prepare command components
        $ttlStr = $ttlMs !== null ? (string)$ttlMs : '-';
        $tagsStr = empty($tags) ? '-' : implode(',', array_map('strval', $tags));
        
        // Escape tab characters in the value to prevent protocol corruption
        $escapedValue = str_replace(["\t", "\n", "\r"], ['\\t', '\\n', '\\r'], $serializedValue);
        
        $resp = $this->cmd("PUT\t{$key}\t{$ttlStr}\t{$tagsStr}\t{$escapedValue}");
        
        if ($resp !== 'OK') {
            throw new ApiException('PUT failed: ' . $resp);
        }
        
        return true;
    }

    /**
     * Retrieve a value with enhanced deserialization
     * @return array<string, mixed>
     * @throws NotFoundException|ConnectionException|TimeoutException|ApiException
     */
    public function get(string $key): array
    {
        $resp = $this->cmd("GET\t{$key}");
        
        if ($resp === 'NF') {
            throw new NotFoundException('Key not found: ' . $key);
        }
        
        if (!preg_match('/^VALUE\t(.*)$/s', $resp, $matches)) {
            throw new ApiException('GET bad response: ' . $resp);
        }
        
        $rawValue = $matches[1];
        
        // Unescape tab characters that were escaped during PUT
        $unescapedValue = str_replace(['\\t', '\\n', '\\r'], ["\t", "\n", "\r"], $rawValue);
        
        // Deserialize the value
        $deserializedValue = $this->performDeserialization($unescapedValue);
        
        return ['value' => $deserializedValue];
    }

    /**
     * Delete a key with enhanced error handling
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function delete(string $key): bool
    {
        $resp = $this->cmd("DEL\t{$key}");
        
        if (str_contains($resp, 'ok')) {
            return true;
        } elseif (str_contains($resp, 'nf')) {
            return false; // Key not found, but deletion is considered successful
        } else {
            throw new ApiException('DELETE failed: ' . $resp);
        }
    }

    /**
     * Invalidate multiple keys with enhanced batch processing
     * @param string[] $keys
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function invalidateKeys(array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }
        
        $count = 0;
        $batchSize = 50; // Process in batches to avoid overwhelming the connection
        
        for ($i = 0; $i < count($keys); $i += $batchSize) {
            $batch = array_slice($keys, $i, $batchSize);
            
            foreach ($batch as $key) {
                try {
                    if ($this->delete($key)) {
                        $count++;
                    }
                } catch (NotFoundException $e) {
                    // Key not found, continue with next
                    continue;
                } catch (\Throwable $e) {
                    // Log error but continue with remaining keys
                    error_log("Failed to delete key '{$key}': " . $e->getMessage());
                }
            }
        }
        
        return $count;
    }

    /**
     * Safely close all connections with proper cleanup
     */
    public function close(): void
    {
        try {
            $this->resetConnectionPool();
        } catch (\Throwable $e) {
            // Log error but don't throw during cleanup
            error_log("Error during TcpTransport connection cleanup: " . $e->getMessage());
        }
    }
    
    /**
     * Invalidate keys by tags with enhanced error handling
     * @param string[] $tags
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function invalidateTags(array $tags, string $mode = 'any'): int
    {
        if (empty($tags)) {
            return 0;
        }
        
        $totalCount = 0;
        
        if ($mode === 'any') {
            foreach ($tags as $tag) {
                try {
                    $resp = $this->cmd("INV_TAG\t{$tag}");
                    $parts = explode("\t", $resp);
                    
                    if ($parts[0] === 'INV_TAG') {
                        $count = (int)($parts[1] ?? 0);
                        $totalCount += $count;
                    } else {
                        throw new ApiException("Invalid INV_TAG response: {$resp}");
                    }
                } catch (\Throwable $e) {
                    error_log("Failed to invalidate tag '{$tag}': " . $e->getMessage());
                }
            }
        } else {
            // 'all' mode not supported over TCP protocol
            throw new ApiException('invalidateTags with mode="all" not supported over TCP transport; use HTTP');
        }
        
        return $totalCount;
    }
    
    /**
     * Get keys by tag with enhanced error handling
     * @return string[]
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function getKeysByTag(string $tag): array
    {
        $resp = $this->cmd("KEYS_BY_TAG\t{$tag}");
        $parts = explode("\t", $resp);
        
        if ($parts[0] !== 'KEYS') {
            throw new ApiException("Invalid KEYS_BY_TAG response: {$resp}");
        }
        
        $keysStr = $parts[1] ?? '';
        return empty($keysStr) ? [] : explode(",", $keysStr);
    }

    /**
     * Bulk get with enhanced error handling and performance optimization
     * @param string[] $keys
     * @return array<string, array<string, mixed>>
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function bulkGet(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        
        $result = [];
        $batchSize = 20; // Smaller batch size for TCP to maintain responsiveness
        
        for ($i = 0; $i < count($keys); $i += $batchSize) {
            $batch = array_slice($keys, $i, $batchSize);
            
            foreach ($batch as $key) {
                try {
                    $result[$key] = $this->get($key);
                } catch (NotFoundException $e) {
                    // Skip keys that don't exist - this matches HTTP transport behavior
                    continue;
                } catch (\Throwable $e) {
                    error_log("Failed to get key '{$key}': " . $e->getMessage());
                    continue;
                }
            }
        }
        
        return $result;
    }

    /**
     * Bulk delete with enhanced batch processing
     * @param string[] $keys
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function bulkDelete(array $keys): int
    {
        return $this->invalidateKeys($keys);
    }
    
    /**
     * Enhanced search with proper error handling and protocol limitations
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws ApiException|ConnectionException|TimeoutException
     */
    public function search(array $params): array
    {
        // Enhanced search support for TCP with better error handling
        if (isset($params['tag_any']) && is_array($params['tag_any'])) {
            $results = [];
            $seen = [];
            
            foreach ($params['tag_any'] as $tag) {
                try {
                    $keys = $this->getKeysByTag($tag);
                    
                    foreach ($keys as $key) {
                        if (!isset($seen[$key])) {
                            $results[] = ['key' => $key];
                            $seen[$key] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("Failed to search by tag '{$tag}': " . $e->getMessage());
                }
            }
            
            return $results;
        }
        
        if (isset($params['tag_all']) && is_array($params['tag_all'])) {
            if (count($params['tag_all']) === 0) {
                return [];
            }
            
            // For tag_all, get intersection of all tag results
            try {
                $firstTag = $params['tag_all'][0];
                $keysets = [$this->getKeysByTag($firstTag)];
                
                for ($i = 1; $i < count($params['tag_all']); $i++) {
                    $keysets[] = $this->getKeysByTag($params['tag_all'][$i]);
                }
                
                // Find intersection
                $intersection = $keysets[0];
                for ($i = 1; $i < count($keysets); $i++) {
                    $intersection = array_intersect($intersection, $keysets[$i]);
                }
                
                $results = [];
                foreach ($intersection as $key) {
                    $results[] = ['key' => $key];
                }
                
                return $results;
            } catch (\Throwable $e) {
                throw new ApiException('tag_all search failed: ' . $e->getMessage(), 0, $e);
            }
        }
        
        // For other search types, throw exception
        throw new ApiException('Complex search queries not supported over TCP transport; use HTTP');
    }

    /**
     * Get comprehensive statistics with enhanced error handling
     * @return array<string, mixed>
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function stats(): array
    {
        $resp = $this->cmd('STATS');
        $parts = explode("\t", $resp);
        
        if ($parts[0] !== 'STATS') {
            throw new ApiException('STATS bad response: ' . $resp);
        }
        
        return [
            'hits' => (int)($parts[1] ?? 0),
            'misses' => (int)($parts[2] ?? 0),
            'puts' => (int)($parts[3] ?? 0),
            'invalidations' => (int)($parts[4] ?? 0),
            'hit_ratio' => (float)($parts[5] ?? 0),
            'transport' => 'tcp',
            'pool_size' => count($this->pool),
            'pool_healthy' => count(array_filter($this->pool, fn($c) => $this->isConnectionHealthy($c))),
            'connection_failures' => $this->connectionFailures,
        ];
    }
    
    /**
     * Get statistics (alias for stats)
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats();
    }
    
    /**
     * List operations not supported over TCP
     * @return string[]
     * @throws ApiException
     */
    public function list(int $limit = 100): array
    {
        throw new ApiException('list not supported over TCP transport; use HTTP');
    }
    
    /**
     * Flush all entries with enhanced error handling
     * @throws ConnectionException|TimeoutException|ApiException
     */
    public function flush(): int
    {
        $resp = $this->cmd('FLUSH');
        $parts = explode("\t", $resp);
        
        if ($parts[0] !== 'FLUSH') {
            throw new ApiException('FLUSH bad response: ' . $resp);
        }
        
        return (int)($parts[1] ?? 0);
    }

    /**
     * Enhanced health check with connection pool monitoring
     * @return array<string, mixed>
     * @throws ConnectionException
     */
    public function health(): array
    {
        try {
            // Test connection establishment
            $testConn = $this->conn();
            
            // Additional health metrics
            $healthyConnections = 0;
            $totalConnections = count($this->pool);
            
            foreach ($this->pool as $connInfo) {
                if ($this->isConnectionHealthy($connInfo)) {
                    $healthyConnections++;
                }
            }
            
            return [
                'status' => 'ok',
                'transport' => 'tcp',
                'host' => $this->host,
                'port' => $this->port,
                'pool_size' => $totalConnections,
                'healthy_connections' => $healthyConnections,
                'connection_failures' => $this->connectionFailures,
                'last_failure_time' => $this->lastFailureTime > 0 ? date('c', (int)$this->lastFailureTime) : null,
                'timeout_ms' => $this->timeoutMs,
                'connect_timeout_ms' => $this->connectTimeoutMs,
                'max_retries' => $this->maxRetries,
            ];
        } catch (\Throwable $e) {
            throw new ConnectionException('TCP health check failed: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Authentication not supported over TCP
     * @throws ApiException
     */
    public function login(string $username, string $password): bool
    {
        throw new ApiException('login not supported over TCP transport; use HTTP');
    }
    
    /**
     * Credential rotation not supported over TCP
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function rotateCredentials(): array
    {
        throw new ApiException('rotateCredentials not supported over TCP transport; use HTTP');
    }
    
    /**
     * Setup check not supported over TCP
     * @throws ApiException
     */
    public function setupRequired(): bool
    {
        throw new ApiException('setupRequired not supported over TCP transport; use HTTP');
    }
}
