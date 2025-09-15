<?php

namespace App\Helper;

class AroggaCacheSafe
{
    private static array $local = [];
    private static $client = null;
    private static bool $extension_healthy = true;

    public static function getClient()
    {
        if (!self::$extension_healthy) {
            throw new \RuntimeException('TagCache extension is in unhealthy state');
        }

        if (self::$client === null) {
            $configs = [
                'mode' => 'tcp',
                'host' => '127.0.0.1',
                'port' => 1984,
                'pool_size' => 4,        // Safe small pool
                'timeout_ms' => 15000,   // Long timeout
                'connect_timeout_ms' => 5000,
            ];

            try {
                self::$client = \TagCache::create($configs);
                if (!self::$client) {
                    throw new \RuntimeException('Failed to create TagCache client');
                }
            } catch (\Exception $e) {
                self::$extension_healthy = false;
                error_log("TagCache extension failed: " . $e->getMessage());
                throw $e;
            }
        }

        return self::$client;
    }

    public static function set(string $key, mixed $value, array $tags = []): void
    {
        try {
            self::getClient()->set($key, $value, $tags, 3600 * 1000);
            self::$local[$key] = self::cloneValue($value);
        } catch (\Exception $e) {
            error_log("TagCache set failed for key '$key': " . $e->getMessage());
            // Graceful degradation - just use local cache
            self::$local[$key] = self::cloneValue($value);
        }
    }

    public static function get(string $key): mixed
    {
        if (array_key_exists($key, self::$local)) {
            return self::cloneValue(self::$local[$key]);
        }

        try {
            $item = self::getClient()->get($key);
            if ($item !== null) {
                self::$local[$key] = self::cloneValue($item);
                return $item;
            }
        } catch (\Exception $e) {
            error_log("TagCache get failed for key '$key': " . $e->getMessage());
            self::$extension_healthy = false; // Mark as unhealthy
        }
        
        return null;
    }

    public static function forget(string $key): bool
    {
        try {
            $res = self::getClient()->delete($key);
            unset(self::$local[$key]);
            return $res;
        } catch (\Exception $e) {
            error_log("TagCache delete failed for key '$key': " . $e->getMessage());
            unset(self::$local[$key]);
            return false;
        }
    }

    public static function clearTag(string $tag): int
    {
        try {
            $count = self::getClient()->invalidateTagsAny([$tag]);
            self::flushLocal();
            return $count;
        } catch (\Exception $e) {
            error_log("TagCache clearTag failed for tag '$tag': " . $e->getMessage());
            self::flushLocal();
            return 0;
        }
    }

    // SAFE bulk operations - avoid the buggy mGet/mSet
    public static function many(array $keys): array
    {
        if (empty($keys)) return [];

        $result = [];
        
        // Use individual gets to avoid bulk operation bug
        foreach ($keys as $key) {
            $result[$key] = self::get($key);
        }
        
        return $result;
    }

    public static function putMany(array $values, array $tags = [], ?int $ttlMs = null): void
    {
        // Use individual sets to avoid bulk operation bug
        foreach ($values as $key => $value) {
            self::set($key, $value, $tags);
        }
    }

    public static function flushLocal(): void 
    { 
        self::$local = []; 
    }

    private static function cloneValue(mixed $value): mixed
    {
        if (is_object($value)) {
            try {
                return clone $value;
            } catch (\Exception $e) {
                return unserialize(serialize($value));
            }
        }
        return $value;
    }

    public static function isHealthy(): bool
    {
        return self::$extension_healthy;
    }

    public static function clearTagMany(string $modelName, array $ids): int
    {
        if (empty($ids)) return 0;
        
        try {
            $tags = array_map(fn($id) => "$modelName:$id", $ids);
            $count = self::getClient()->invalidateTagsAny($tags);
            self::flushLocal();
            return $count;
        } catch (\Exception $e) {
            error_log("TagCache clearTagMany failed for model '$modelName': " . $e->getMessage());
            self::flushLocal();
            return 0;
        }
    }
}