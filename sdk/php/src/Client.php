<?php

namespace TagCache\SDK;

use TagCache\SDK\Contracts\ClientInterface;
use TagCache\SDK\Models\Item;
use TagCache\SDK\Transport\HttpTransport;
use TagCache\SDK\Transport\TcpTransport;
use TagCache\SDK\Transport\TransportInterface;

final class Client implements ClientInterface
{
    private Config $config;
    private TransportInterface $transport;

    public function __construct(Config $config, ?TransportInterface $transport = null)
    {
        $this->config = $config;
        if ($transport) { $this->transport = $transport; }
        else {
            $mode = strtolower($config->mode);
            if ($mode === 'tcp') $this->transport = new TcpTransport($config);
            elseif ($mode === 'auto') {
                try { $this->transport = new TcpTransport($config); }
                catch (\Throwable $e) { $this->transport = new HttpTransport($config); }
            } else { $this->transport = new HttpTransport($config); }
        }
    }

    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): void
    {
        $this->transport->put($key, $value, $ttlMs, $tags);
    }

    public function get(string $key): ?Item
    {
        $res = $this->transport->get($key);
        if ($res === null) return null;
        // Map flexible response into Item
        if (isset($res['key'])) {
            return new Item($res['key'], $res['value'] ?? null, $res['ttl_ms'] ?? null, $res['tags'] ?? [], $res['created_ms'] ?? null);
        }
        // GET handler may return { value } only
        $value = $res['value'] ?? null;
        return new Item($key, $value);
    }

    public function delete(string $key): bool
    {
        return $this->transport->delete($key);
    }

    public function invalidateKeys(array $keys): int
    {
        return $this->transport->invalidateKeys($keys);
    }

    public function invalidateTags(array $tags, string $mode = 'any'): int
    {
        return $this->transport->invalidateTags($tags, $mode);
    }

    public function bulkGet(array $keys): array
    {
        $raw = $this->transport->bulkGet($keys);
        $out = [];
        foreach ($raw as $k => $res) {
            $out[$k] = $res ? (isset($res['key']) ? new Item($res['key'], $res['value'] ?? null, $res['ttl_ms'] ?? null, $res['tags'] ?? [], $res['created_ms'] ?? null) : new Item($k, $res['value'] ?? null)) : null;
        }
        return $out;
    }

    public function bulkDelete(array $keys): int
    {
        return $this->transport->bulkDelete($keys);
    }

    public function search(array $params): array
    {
        return $this->transport->search($params);
    }

    public function stats(): array
    {
        return $this->transport->stats();
    }

    public function list(int $limit = 100): array
    {
        return $this->transport->list($limit);
    }

    public function getOrSet(string $key, callable $producer, ?int $ttlMs = null, array $tags = []): Item
    {
        $found = $this->get($key);
        if ($found) return $found;
        $value = $producer($key);
        $this->put($key, $value, $ttlMs, $tags);
        return new Item($key, $value, $ttlMs, $tags);
    }

    public function keysByTag(string $tag, ?int $limit = null): array
    {
        $res = $this->search(['tag_any' => [$tag], 'limit' => $limit]);
        $items = [];
        foreach (($res['keys'] ?? []) as $it) {
            $items[] = new Item($it['key'], $it['value'] ?? null, $it['ttl_ms'] ?? null, $it['tags'] ?? [], $it['created_ms'] ?? null);
        }
        return $items;
    }

    public function keysByTagsAny(array $tags, ?int $limit = null): array
    {
        $res = $this->search(['tag_any' => array_values($tags), 'limit' => $limit]);
        $items = [];
        foreach (($res['keys'] ?? []) as $it) {
            $items[] = new Item($it['key'], $it['value'] ?? null, $it['ttl_ms'] ?? null, $it['tags'] ?? [], $it['created_ms'] ?? null);
        }
        return $items;
    }

    public function keysByTagsAll(array $tags, ?int $limit = null): array
    {
        $res = $this->search(['tag_all' => array_values($tags), 'limit' => $limit]);
        $items = [];
        foreach (($res['keys'] ?? []) as $it) {
            $items[] = new Item($it['key'], $it['value'] ?? null, $it['ttl_ms'] ?? null, $it['tags'] ?? [], $it['created_ms'] ?? null);
        }
        return $items;
    }

    public function flush(): int
    {
        return $this->transport->flush();
    }

    public function health(): array
    {
        return $this->transport->health();
    }

    public function login(string $username, string $password): string
    {
        return $this->transport->login($username, $password);
    }

    public function rotateCredentials(): array
    {
        return $this->transport->rotateCredentials();
    }

    public function setupRequired(): bool
    {
        return $this->transport->setupRequired();
    }
}
