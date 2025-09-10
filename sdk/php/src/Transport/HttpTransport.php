<?php

namespace TagCache\Transport;

use TagCache\Config;
use TagCache\Exceptions\ApiException;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\TimeoutException;
use TagCache\Exceptions\UnauthorizedException;
use TagCache\Exceptions\ServerException;

final class HttpTransport implements TransportInterface
{
    private string $baseUrl;
    private int $timeoutMs;
    private int $maxRetries;
    private int $retryDelayMs;
    private ?string $token;
    private ?string $basicUser;
    private ?string $basicPass;

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
            } catch (ConnectionException | TimeoutException $e) {
                $lastError = $e;
                if ($attempt === $this->maxRetries) break;
                continue; // retry
            } catch (\Throwable $e) {
                throw $e; // don't retry non-connection errors
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
        $url = $this->baseUrl . $path;
        // If GET with params, append as query string (server expects query for many endpoints)
        if (strtoupper($method) === 'GET' && $json !== null) {
            $qs = http_build_query($json);
            $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
            $json = null; // don't send body on GET
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($method !== '') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeoutMs);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int)($this->timeoutMs * 0.3)); // 30% of total timeout for connection
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // Build headers; prefer Bearer token if present, else Basic auth if available
        $authHeader = null;
        if (!empty($this->token)) {
            $authHeader = 'Authorization: Bearer '.$this->token;
        } elseif ($this->basicUser !== null && $this->basicPass !== null) {
            $authHeader = 'Authorization: Basic '.base64_encode($this->basicUser.':'.$this->basicPass);
        }
        $headers = ['Content-Type: application/json', 'Connection: keep-alive'];
        if ($authHeader !== null) $headers[] = $authHeader;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_THROW_ON_ERROR));
        }
        
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($resp === false) {
            if ($errno === CURLE_OPERATION_TIMEOUTED) {
                throw new TimeoutException('Request timeout: '.$error);
            }
            throw new ConnectionException('Connection error: '.$error);
        }
        
        if (!is_string($resp)) {
            throw new ServerException('Invalid response from server');
        }
        
        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ServerException('Invalid JSON response: '.json_last_error_msg());
        }
        
        if ($code >= 500) {
            throw new ServerException('Server error '.$code.': '.($data['error'] ?? $resp));
        }
        if ($code === 401) {
            // Try to exchange provided basic credentials for a token once, then retry automatically.
            if ($this->basicUser !== null && $this->basicPass !== null && empty($this->token)) {
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
            throw new ApiException('HTTP '.$code.': '.($data['error'] ?? $resp));
        }
        
        return is_array($data) ? $data : [];
    }

    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool
    {
        // server expects /keys/:key { value, ttl_ms, tags }
        $this->request('PUT', '/keys/'.rawurlencode($key), [
            'value' => $value,
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
    if (isset($res['error']) && in_array($res['error'], ['not_found', 'not found', 'not_found'])) throw new NotFoundException($res['error']);
        // normalize into item shape if REST provides metadata endpoint
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
    foreach ($items as $it) { $map[$it['key']] = $it; }
    // Preserve requested order and include nulls for missing
    $out = [];
    foreach ($keys as $k) { $out[$k] = $map[$k] ?? null; }
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
        // HTTP transport doesn't need to close connections
    }
}
