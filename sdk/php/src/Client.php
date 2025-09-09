<?php declare(strict_types=1);

namespace TagCache;

use TagCache\Contracts\ClientInterface;
use TagCache\Models\Item;
use TagCache\Transport\HttpTransport;
use TagCache\Transport\TcpTransport;
use TagCache\Transport\TransportInterface;

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

    // Note: tests expect signature put(key, value, ttlMs, tags)
    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool
    {
        try {
            $this->transport->put($key, $value, $ttlMs, $tags);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function get(string $key): mixed
    {
        $res = $this->transport->get($key);
        if ($res === null) return null;
        // Tests generally expect raw value when get() used directly for backward compatibility
        if (isset($res['value'])) return $res['value'];
        if (isset($res['value_raw'])) return $res['value_raw'];
        if (isset($res['key']) && isset($res['value'])) return $res['value'];
        return $res; // fallback: return array/metadata
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
    
    public function getStats(): array
    {
        return $this->stats();
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
        $this->put($key, $value, $tags, $ttlMs);
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
    
    // Helper methods for convenience
    public function putWithTag(string $key, mixed $value, string $tag, ?int $ttlMs = null): bool
    {
        return $this->put($key, $value, [$tag], $ttlMs);
    }
    
    public function deleteByTag(string $tag): int
    {
        return $this->invalidateTags([$tag]);
    }
    
    public function getKeysByTag(string $tag): array
    {
        return $this->keysByTag($tag);
    }
    
    public function invalidateByTag(string $tag): bool
    {
        return $this->invalidateTags([$tag]) > 0;
    }
    
    public function invalidateByKey(string $key): bool
    {
        return $this->delete($key);
    }
}
