<?php declare(strict_types=1);

namespace TagCache;

use TagCache\Contracts\ClientInterface;
use TagCache\Exceptions\NotFoundException;
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

    /**
     * @param string[] $tags
     */
    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool
    {
        try {
            return $this->transport->put($key, $value, $ttlMs, $tags);
        } catch (\Throwable $e) {
            // Log error in debug mode
            if (($this->config->http['debug'] ?? false) || ($this->config->tcp['debug'] ?? false)) {
                error_log("TagCache put error: " . $e->getMessage());
            }
            return false;
        }
    }

    public function get(string $key): mixed
    {
        try {
            $res = $this->transport->get($key);
            // Tests generally expect raw value when get() used directly for backward compatibility
            if (isset($res['value'])) return $res['value'];
            if (isset($res['value_raw'])) return $res['value_raw'];
            if (isset($res['key']) && isset($res['value'])) return $res['value'];
            return $res; // fallback: return array/metadata
        } catch (NotFoundException $e) {
            return null;
        }
    }

    public function delete(string $key): bool
    {
        return $this->transport->delete($key);
    }

    /**
     * @param string[] $keys
     */
    public function invalidateKeys(array $keys): int
    {
        return $this->transport->invalidateKeys($keys);
    }

    /**
     * @param string[] $tags
     */
    public function invalidateTags(array $tags, string $mode = 'any'): int
    {
        return $this->transport->invalidateTags($tags, $mode);
    }

    /**
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function bulkGet(array $keys): array
    {
        $raw = $this->transport->bulkGet($keys);
        $out = [];
        foreach ($raw as $k => $res) {
            // Return raw values for backward compatibility, like get() method
            if ($res) {
                if (isset($res['value'])) $out[$k] = $res['value'];
                elseif (isset($res['value_raw'])) $out[$k] = $res['value_raw'];
                else $out[$k] = $res;
            }
            // Skip missing keys (don't add them to result)
        }
        return $out;
    }

    /**
     * @param string[] $keys
     */
    public function bulkDelete(array $keys): int
    {
        return $this->transport->bulkDelete($keys);
    }

    /**
     * @param array<string, mixed>|string $params Search parameters or pattern string
     * @return array<string, mixed>
     */
    public function search(array|string $params): array
    {
        // Handle backward compatibility: if string is passed, convert to pattern search
        if (is_string($params)) {
            $params = ['pattern' => $params];
        }
        $response = $this->transport->search($params);
        return $response['keys'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->transport->stats();
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

    /**
     * @return array<string>
     */
    public function keysByTag(string $tag, ?int $limit = null): array
    {
        $res = $this->search(['tag_any' => [$tag], 'limit' => $limit]);
        $keys = [];
        foreach (($res['keys'] ?? []) as $it) {
            $keys[] = $it['key'];
        }
        return $keys;
    }

    /**
     * @return array<string>
     */
    public function keysByTagsAny(array $tags, ?int $limit = null): array
    {
        $res = $this->search(['tag_any' => array_values($tags), 'limit' => $limit]);
        $keys = [];
        foreach (($res['keys'] ?? []) as $it) {
            $keys[] = $it['key'];
        }
        return $keys;
    }

    /**
     * @return array<string>
     */
    public function keysByTagsAll(array $tags, ?int $limit = null): array
    {
        $res = $this->search(['tag_all' => array_values($tags), 'limit' => $limit]);
        $keys = [];
        foreach (($res['keys'] ?? []) as $it) {
            $keys[] = $it['key'];
        }
        return $keys;
    }

    public function flush(): int
    {
        return $this->transport->flush();
    }

    public function health(): array
    {
        return $this->transport->health();
    }

    public function login(string $username, string $password): bool
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
    
    // Helper methods for convenience and test compatibility
    public function putWithTag(string $key, mixed $value, string $tag, ?int $ttlMs = null): bool
    {
        return $this->put($key, $value, $ttlMs, [$tag]);
    }
    
    public function deleteByTag(string $tag): int
    {
        return $this->invalidateTags([$tag]);
    }
    
    /**
     * @return array<string>
     */
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

    /**
     * Get transport instance for advanced usage
     */
    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Get configuration instance
     */
    public function getConfig(): Config
    {
        return $this->config;
    }
}
