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
    private ?string $token;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim($config->http['base_url'] ?? 'http://127.0.0.1:8080', '/');
        $this->timeoutMs = (int)($config->http['timeout_ms'] ?? 5000);
        $this->maxRetries = (int)($config->http['retries'] ?? 3);
        $this->token = ($config->auth['token'] ?? '') ?: null;
    }

    private function request(string $method, string $path, ?array $json = null): array
    {
        $lastError = null;
        
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep(min(1000000, 100000 * (2 ** ($attempt - 1)))); // exponential backoff
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
    
    private function doRequest(string $method, string $path, ?array $json = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeoutMs);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min($this->timeoutMs, 2000));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_filter([
            'Content-Type: application/json',
            'Connection: keep-alive',
            $this->token ? 'Authorization: Bearer '.$this->token : null,
        ])));
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
        
        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ServerException('Invalid JSON response: '.json_last_error_msg());
        }
        
        if ($code >= 500) {
            throw new ServerException('Server error '.$code.': '.($data['error'] ?? $resp));
        }
        if ($code === 401) {
            throw new UnauthorizedException($data['error'] ?? 'Unauthorized');
        }
        if ($code === 404) {
            throw new NotFoundException($data['error'] ?? 'Not found');
        }
        if ($code >= 400) {
            throw new ApiException('HTTP '.$code.': '.($data['error'] ?? $resp));
        }
        
        return is_array($data) ? $data : [];
    }

    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): void
    {
        // server expects /keys/:key { value, ttl_ms, tags }
        $this->request('PUT', '/keys/'.rawurlencode($key), [
            'value' => $value,
            'ttl_ms' => $ttlMs,
            'tags' => array_values(array_unique(array_map('strval', $tags)))
        ]);
    }

    public function get(string $key): ?array
    {
        $res = $this->request('GET', '/keys/'.rawurlencode($key));
        if (isset($res['error']) && $res['error'] === 'not_found') return null;
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
    
    public function getKeysByTag(string $tag): array
    {
        $response = $this->request('GET', '/keys', ['tag' => $tag]);
        return $response['keys'] ?? [];
    }

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

    public function search(array $params): array
    {
        return $this->request('POST', '/search', $params);
    }

    public function stats(): array
    {
        return $this->request('GET', '/stats');
    }
    
    public function getStats(): array
    {
        return $this->stats();
    }

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

    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    public function login(string $username, string $password): string
    {
        $res = $this->request('POST', '/auth/login', ['username' => $username, 'password' => $password]);
        if (!isset($res['token'])) {
            throw new ApiException('Login failed: no token returned');
        }
        return $res['token'];
    }

    public function rotateCredentials(): array
    {
        return $this->request('POST', '/auth/rotate');
    }

    public function setupRequired(): bool
    {
        $res = $this->request('GET', '/auth/setup_required');
        return (bool)($res['setup_required'] ?? false);
    }
}
