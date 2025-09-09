<?php

namespace TagCache\SDK\Transport;

use TagCache\SDK\Config;
use TagCache\SDK\Exceptions\ApiException;
use TagCache\SDK\Exceptions\NotFoundException;

final class HttpTransport implements TransportInterface
{
    private string $baseUrl;
    private int $timeoutMs;
    private ?string $token;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim($config->http['base_url'] ?? 'http://127.0.0.1:8080', '/');
        $this->timeoutMs = (int)($config->http['timeout_ms'] ?? 5000);
        $this->token = ($config->auth['token'] ?? '') ?: null;
    }

    private function request(string $method, string $path, ?array $json = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeoutMs);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_filter([
            'Content-Type: application/json',
            $this->token ? 'Authorization: Bearer '.$this->token : null,
        ])));
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ApiException('HTTP error: '.$err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($resp, true);
        if ($code >= 400) {
            if ($code === 404) throw new NotFoundException($data['error'] ?? 'not found');
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
        $res = $this->request('POST', '/invalidate/tags', [ 'tags' => array_values($tags), 'mode' => $mode ]);
        return (int)($res['count'] ?? 0);
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

    public function list(int $limit = 100): array
    {
        $res = $this->request('GET', '/keys');
        $keys = $res['keys'] ?? [];
        if ($limit > 0 && count($keys) > $limit) {
            $keys = array_slice($keys, 0, $limit);
        }
        return $keys;
    }
}
