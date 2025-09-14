<?php

namespace TagCache\Integrations\Laravel;

use Illuminate\Cache\CacheLock;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\InteractsWithTime;
use TagCache\Client;
use TagCache\Contracts\ClientInterface;

/**
 * Laravel Cache Store implementation for TagCache backend.
 * Supports tagging & locks similar to Redis / Memcached drivers.
 */
class TagCacheStore extends TaggableStore implements LockProvider, Store
{
    use InteractsWithTime;

    protected ClientInterface $client;

    /** @var string */
    protected $prefix;

    public function __construct(ClientInterface $client, string $prefix = '')
    {
        $this->client = $client;
        $this->setPrefix($prefix);
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get($key)
    {
        return $this->client->get($this->prefix.$key);
    }

    /**
     * Retrieve multiple items from the cache by key.
     * Items not found will have null values (Laravel expectation).
     * @param array $keys
     * @return array<string,mixed>
     */
    public function many(array $keys)
    {
        if (count($keys) === 0) return [];
        $prefixed = array_map(fn($k) => $this->prefix.$k, $keys);
        $raw = $this->client->bulkGet($prefixed);
        $results = [];
        foreach ($keys as $k) {
            $pk = $this->prefix.$k;
            $results[$k] = $raw[$pk] ?? null; // missing => null
        }
        return $results;
    }

    /**
     * Store an item for a number of seconds.
     */
    public function put($key, $value, $seconds)
    {
        $ttlMs = $seconds > 0 ? $seconds * 1000 : null; // null -> use server default / forever semantics
        return $this->client->put($this->prefix.$key, $value, [], $ttlMs);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, $seconds)
    {
        $ok = true;
        foreach ($values as $k => $v) {
            $ok = $this->put($k, $v, $seconds) && $ok;
        }
        return $ok;
    }

    /**
     * Store an item if key doesn't exist (non-atomic fallback implementation).
     * NOTE: This is not perfectly race-safe; underlying TagCache client doesn't expose atomic add yet.
     */
    public function add($key, $value, $seconds)
    {
        if ($this->get($key) !== null) return false;
        return $this->put($key, $value, $seconds);
    }

    /**
     * Increment numeric value.
     * If value is missing, initialize to value.
     */
    public function increment($key, $value = 1)
    {
        $current = $this->get($key);
        if (!is_numeric($current)) {
            $current = 0;
        }
        $new = (int)$current + $value;
        // Preserve existing TTL? Not tracked in client; just overwrite.
        $this->put($key, $new, 0); // 0 -> treat as forever
        return $new;
    }

    /**
     * Decrement numeric value.
     */
    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    /**
     * Store an item forever.
     */
    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Get a lock instance.
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        // Use simple CacheLock which uses add()/put()/forget() semantics.
        return new CacheLock($this, $this->prefix.$name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Remove an item.
     */
    public function forget($key)
    {
        return $this->client->delete($this->prefix.$key);
    }

    /**
     * Flush all cached data.
     */
    public function flush()
    {
        $this->client->flush();
        return true;
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Set key prefix.
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }
}
