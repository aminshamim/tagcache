<?php

namespace TagCache\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException as GuzzleServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\RequestOptions;
use TagCache\Config;
use TagCache\Exceptions\ApiException;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\ConfigurationException;
use TagCache\Exceptions\TimeoutException;
use TagCache\Exceptions\UnauthorizedException;
use TagCache\Exceptions\ServerException;

final class HttpTransport implements TransportInterface
{
    private Client $client;
    private string $baseUrl;
    private int $timeoutMs;
    private int $maxRetries;
    private int $retryDelayMs;
    private ?string $token;
    private ?string $basicUser;
    private ?string $basicPass;
    private string $serializer;
    private bool $autoSerialize;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim($config->http['base_url'] ?? 'http://127.0.0.1:8080', '/');
        $this->timeoutMs = (int)($config->http['timeout_ms'] ?? 5000);
        $this->maxRetries = (int)($config->http['max_retries'] ?? $config->http['retries'] ?? 3);
        $this->retryDelayMs = (int)($config->http['retry_delay_ms'] ?? 100);
        $this->token = ($config->auth['token'] ?? '') ?: null;
        // Support basic auth username/password
        $this->basicUser = $config->auth['username'] ?? null;
        $this->basicPass = $config->auth['password'] ?? null;
        
        // Initialize serialization settings
        $this->serializer = $this->validateSerializer($config->http['serializer'] ?? 'native');
        $this->autoSerialize = (bool)($config->http['auto_serialize'] ?? true);

        // Initialize Guzzle client
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeoutMs / 1000, // Guzzle expects seconds
            'connect_timeout' => ($this->timeoutMs * 0.3) / 1000, // 30% of total timeout for connection
            'allow_redirects' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Connection' => 'keep-alive',
            ],
        ]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $lastError = null;
        
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep(min(1000000, (int)($this->retryDelayMs * (2 ** ($attempt - 1))) * 1000)); // exponential backoff from configured ms
            }
            
            try {
                return $this->doRequest($method, $path, $json);
            } catch (ConnectionException | TimeoutException | ServerException $e) {
                // Retry connection errors, timeouts, and server errors (5xx)
                $lastError = $e;
                if ($attempt === $this->maxRetries) break;
                continue; // retry
            } catch (UnauthorizedException | NotFoundException | ApiException $e) {
                // Don't retry client errors (4xx) - these indicate permanent issues
                throw $e;
            } catch (\Throwable $e) {
                // Don't retry other unexpected errors
                throw $e;
            }
        }
        
        throw $lastError ?? new ConnectionException('Request failed after retries');
    }
    
    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function doRequest(string $method, string $path, ?array $json = null): array
    {
        try {
            $options = [
                RequestOptions::HEADERS => $this->buildHeaders(),
            ];

            // If GET with params, append as query string
            if (strtoupper($method) === 'GET' && $json !== null) {
                $options[RequestOptions::QUERY] = $json;
                $json = null; // don't send body on GET
            }

            if ($json !== null) {
                $options[RequestOptions::JSON] = $json;
            }

            $response = $this->client->request($method, $path, $options);
            $body = $response->getBody()->getContents();
            
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ServerException('Invalid JSON response: '.json_last_error_msg());
            }

            return is_array($data) ? $data : [];

        } catch (ConnectException $e) {
            throw new ConnectionException('Connection error: ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $code = $response->getStatusCode();
                $body = $response->getBody()->getContents();
                
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $data = ['error' => $body];
                }

                if ($code >= 500) {
                    throw new ServerException('Server error '.$code.': '.($data['error'] ?? $body));
                }
                
                if ($code === 401) {
                    // Try to exchange provided basic credentials for a token once, then retry automatically.
                    // Avoid infinite recursion by only doing this for non-login requests
                    if ($this->basicUser !== null && $this->basicPass !== null && empty($this->token) && $path !== '/auth/login') {
                        try {
                            $loginRes = $this->doRequest('POST', '/auth/login', ['username' => $this->basicUser, 'password' => $this->basicPass]);
                            if (isset($loginRes['token'])) {
                                $this->token = $loginRes['token'];
                                // retry original request with token
                                return $this->doRequest($method, $path, $json);
                            }
                        } catch (\Throwable $le) {
                            // fall through to Unauthorized below
                        }
                    }
                    throw new UnauthorizedException($data['error'] ?? 'Unauthorized');
                }
                
                if ($code === 404) {
                    // Some handlers return { error: 'not_found' } with 200; tests expect NotFoundException on 404
                    throw new NotFoundException($data['error'] ?? 'Not found');
                }
                
                if ($code >= 400) {
                    throw new ApiException('HTTP '.$code.': '.($data['error'] ?? $body));
                }
            } else {
                // Handle timeout and connection errors
                if (str_contains($e->getMessage(), 'timeout') || str_contains($e->getMessage(), 'timed out')) {
                    throw new TimeoutException('Request timeout: ' . $e->getMessage(), 0, $e);
                }
                throw new ConnectionException('Connection error: ' . $e->getMessage(), 0, $e);
            }
        } catch (GuzzleServerException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);
            throw new ServerException('Server error '.$response->getStatusCode().': '.($data['error'] ?? $body));
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);
            
            if ($code === 401) {
                throw new UnauthorizedException($data['error'] ?? 'Unauthorized');
            }
            if ($code === 404) {
                throw new NotFoundException($data['error'] ?? 'Not found');
            }
            throw new ApiException('HTTP '.$code.': '.($data['error'] ?? $body));
        } catch (TooManyRedirectsException $e) {
            throw new ConnectionException('Too many redirects: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build HTTP headers for requests
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [];
        
        // Build auth header; prefer Bearer token if present, else Basic auth if available
        if (!empty($this->token)) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        } elseif ($this->basicUser !== null && $this->basicPass !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->basicUser . ':' . $this->basicPass);
        }
        
        return $headers;
    }

    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool
    {
        // Serialize the value if needed
        $serializedValue = $this->serializeValue($value);
        
        // server expects /keys/:key { value, ttl_ms, tags }
        $this->request('PUT', '/keys/'.rawurlencode($key), [
            'value' => $serializedValue,
            'ttl_ms' => $ttlMs,
            'tags' => array_values(array_unique(array_map('strval', $tags)))
        ]);
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $res = $this->request('GET', '/keys/'.rawurlencode($key));
        if (isset($res['error']) && in_array($res['error'], ['not_found', 'not found', 'not_found'])) {
            throw new NotFoundException($res['error']);
        }
        
        // Deserialize the value if needed (use array_key_exists since value might be null)
        if (array_key_exists('value', $res)) {
            $res['value'] = $this->deserializeValue($res['value']);
        }
        
        return $res;
    }

    public function delete(string $key): bool
    {
        $res = $this->request('DELETE', '/keys/'.rawurlencode($key));
        return (bool)($res['ok'] ?? $res['deleted'] ?? false);
    }

    public function invalidateKeys(array $keys): int
    {
        $res = $this->request('POST', '/invalidate/keys', [ 'keys' => array_values($keys) ]);
        return (int)($res['count'] ?? 0);
    }

    public function invalidateTags(array $tags, string $mode = 'any'): int
    {
        return $this->request('POST', '/invalidate/tags', ['tags' => $tags, 'mode' => $mode])['count'] ?? 0;
    }
    
    /**
     * @return array<string>
     */
    public function getKeysByTag(string $tag): array
    {
        error_log("HttpTransport.getKeysByTag: tag='$tag'");
        $response = $this->request('GET', '/keys-by-tag', ['tag' => $tag]);
        error_log("HttpTransport.getKeysByTag response: " . json_encode($response));
        return $response['keys'] ?? [];
    }

    /**
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function bulkGet(array $keys): array
    {
        $res = $this->request('POST', '/keys/bulk/get', ['keys' => array_values($keys)]);
        $items = $res['items'] ?? [];
        $map = [];
        
        foreach ($items as $item) {
            // Deserialize the value if needed (use array_key_exists since value might be null)
            if (array_key_exists('value', $item)) {
                $item['value'] = $this->deserializeValue($item['value']);
            }
            $map[$item['key']] = $item;
        }
        
        // Preserve requested order and include nulls for missing
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $map[$k] ?? null;
        }
        return $out;
    }

    public function bulkDelete(array $keys): int
    {
    $res = $this->request('POST', '/keys/bulk/delete', ['keys' => array_values($keys)]);
    return (int)($res['count'] ?? 0);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function search(array $params): array
    {
        return $this->request('POST', '/search', $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
    $res = $this->request('GET', '/stats');
    // Normalize to tests expectation
    if (!isset($res['total_memory_usage']) && isset($res['bytes'])) { $res['total_memory_usage'] = $res['bytes']; }
    if (!isset($res['total_keys']) && isset($res['items'])) { $res['total_keys'] = $res['items']; }
    return $res;
    }
    
    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats();
    }

    /**
     * @return array<string, mixed>
     */
    public function list(int $limit = 100): array
    {
        $res = $this->request('GET', '/keys');
        $keys = $res['keys'] ?? [];
        if ($limit > 0 && count($keys) > $limit) {
            $keys = array_slice($keys, 0, $limit);
        }
        return $keys;
    }

    public function flush(): int
    {
        $res = $this->request('POST', '/flush');
        return (int)($res['count'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    public function login(string $username, string $password): bool
    {
        $res = $this->request('POST', '/auth/login', ['username' => $username, 'password' => $password]);
        if (!isset($res['token'])) {
            return false;
        }
        $this->token = $res['token'];
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rotateCredentials(): array
    {
        return $this->request('POST', '/auth/rotate');
    }

    public function setupRequired(): bool
    {
        $res = $this->request('GET', '/auth/setup_required');
        return (bool)($res['setup_required'] ?? false);
    }

    public function close(): void
    {
        // Guzzle automatically manages connections and doesn't need explicit closing
        // but we can clear the reference to help with memory management
        unset($this->client);
    }

    /**
     * Validate and determine the best available serializer
     */
    private function validateSerializer(string $preferred): string
    {
        // Check if the preferred serializer is available
        return match($preferred) {
            'igbinary' => function_exists('igbinary_serialize') && function_exists('igbinary_unserialize') ? 'igbinary' : 
                         throw new ConfigurationException("igbinary serializer is configured but igbinary extension is not available. Please install php-igbinary extension or change the serializer configuration."),
            'msgpack' => function_exists('msgpack_pack') && function_exists('msgpack_unpack') ? 'msgpack' : 
                        throw new ConfigurationException("msgpack serializer is configured but msgpack extension is not available. Please install php-msgpack extension or change the serializer configuration."),
            'native' => 'native',
            default => 'native' // Always fall back to native PHP serialization
        };
    }

    /**
     * Serialize a value for storage
     * 
     * @param mixed $value The value to serialize
     * @return string The serialized value
     */
    private function serializeValue(mixed $value): string
    {
        // If auto-serialization is disabled, only handle strings
        if (!$this->autoSerialize) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Auto-serialization is disabled. Only string values are allowed.');
            }
            return $value;
        }

        // Handle primitives that don't need serialization
        if (is_string($value)) {
            return $value;
        }
        
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        
        if (is_bool($value)) {
            return $value ? '__TC_TRUE__' : '__TC_FALSE__';
        }
        
        if (is_null($value)) {
            return '__TC_NULL__';
        }

        // For complex types (arrays, objects), use direct serialization
        $serialized = $this->performSerialization($value);
        return '__TC_SERIALIZED__' . base64_encode($serialized);
    }

    /**
     * Deserialize a value from storage
     * 
     * @param string $value The stored value
     * @return mixed The deserialized value
     */
    private function deserializeValue(string $value): mixed
    {
        // Check for special markers
        if ($value === '__TC_NULL__') {
            return null;
        }
        
        if ($value === '__TC_TRUE__') {
            return true;
        }
        
        if ($value === '__TC_FALSE__') {
            return false;
        }
        
        // Check for serialized data
        if (str_starts_with($value, '__TC_SERIALIZED__')) {
            $serializedData = substr($value, strlen('__TC_SERIALIZED__'));
            $decodedData = base64_decode($serializedData);
            return $this->performDeserialization($decodedData);
        }
        
        // For backward compatibility, try to detect numeric values
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float)$value;
            } else {
                return (int)$value;
            }
        }
        
        // Default to string
        return $value;
    }

    /**
     * Perform the actual serialization using the configured serializer
     */
    private function performSerialization(mixed $value): string
    {
        return match($this->serializer) {
            'igbinary' => function_exists('igbinary_serialize') ? igbinary_serialize($value) : serialize($value),
            'msgpack' => function_exists('msgpack_pack') ? msgpack_pack($value) : serialize($value),
            'native' => serialize($value),
            default => serialize($value)
        };
    }

    /**
     * Perform the actual deserialization using the configured serializer
     */
    private function performDeserialization(string $data): mixed
    {
        return match($this->serializer) {
            'igbinary' => function_exists('igbinary_unserialize') ? igbinary_unserialize($data) : unserialize($data),
            'msgpack' => function_exists('msgpack_unpack') ? msgpack_unpack($data) : unserialize($data),
            'native' => unserialize($data),
            default => unserialize($data)
        };
    }
}
