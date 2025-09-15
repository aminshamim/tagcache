<?php

namespace App\Helper;

class AroggaCache
{
	/** @var array<string,mixed> */
	private static array $local = [];

	private static $client = null;

	/**
	 * Get TagCache client singleton.
	 */
	public static function getClient()
	{
		if (self::$client === null) {
			$configs = [
				'mode' => 'tcp',
				'host' => '127.0.0.1',
				'port' => 1984,
				'pool_size' => 36,
				// Fixed timeout configuration
				'timeout_ms' => 10000,           // 10 seconds for operations (was 'read_timeout')
				'connect_timeout_ms' => 5000,    // 5 seconds for connection (was 'connection_timeout')
				'http_base' => 'http://127.0.0.1:8080', // HTTP fallback
			];

			try {
				self::$client = \TagCache::create($configs);
				if (!self::$client) {
					throw new \RuntimeException('Failed to create TagCache client');
				}
			} catch (\Exception $e) {
				error_log("TagCache connection failed: " . $e->getMessage());
				throw new \RuntimeException('TagCache initialization failed: ' . $e->getMessage());
			}
		}

		return self::$client;
	}

	/** Clear only the local (in-process) memoized entries (including tag indices). */
	public static function flushLocal(): void { 
		self::$local = []; 
	}

	/**
	 * Set a value with tags. Also populates local memo.
	 * @param string[] $tags
	 */
	public static function set(string $key, mixed $value, array $tags = []): void
	{
		try {
			self::getClient()->set($key, $value, $tags, 3600 * 1000); // 1 hour TTL
			self::$local[$key] = self::cloneValue($value);
		} catch (\Exception $e) {
			error_log("TagCache set failed for key '$key': " . $e->getMessage());
			// Still cache locally for resilience
			self::$local[$key] = self::cloneValue($value);
		}
	}

	/**
	 * Get a value (uses local memo first; on miss hits remote once per request).
	 */
	public static function get(string $key): mixed
	{
		if (array_key_exists($key, self::$local)) {
			// Return a fresh clone so mutations by caller won't affect stored local copy
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
		}
		
		return null;
	}

	/** Forget one key (remote + local). */
	public static function forget(string $key): bool
	{
		try {
			$res = self::getClient()->delete($key);
			unset(self::$local[$key]);
			return $res;
		} catch (\Exception $e) {
			error_log("TagCache delete failed for key '$key': " . $e->getMessage());
			unset(self::$local[$key]); // At least clear local
			return false;
		}
	}

	/** Invalidate a tag (flushes local memo entirely for safety). */
	public static function clearTag(string $tag): int
	{
		try {
			$count = self::getClient()->invalidateTagsAny([$tag]);
			self::flushLocal();
			return $count;
		} catch (\Exception $e) {
			error_log("TagCache clearTag failed for tag '$tag': " . $e->getMessage());
			self::flushLocal(); // At least clear local
			return 0;
		}
	}

	/** Invalidate many tags built from model name + ids. */
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
			self::flushLocal(); // At least clear local
			return 0;
		}
	}

	/**
	 * Bulk get with local memo integration.
	 * @return array<string,mixed>
	 */
	public static function many(array $keys): array
	{
		if (empty($keys)) return [];

		$result = [];
		$misses = [];
		
		// Check local cache first
		foreach ($keys as $k) {
			if (array_key_exists($k, self::$local)) {
				$result[$k] = self::cloneValue(self::$local[$k]);
			} else {
				$misses[] = $k;
			}
		}

		// Fetch misses from remote
		if (!empty($misses)) {
			try {
				$fetched = self::getClient()->mGet($misses);
				foreach ($misses as $m) {
					$item = $fetched[$m] ?? null;
					$result[$m] = $item;
					if ($item !== null) {
						self::$local[$m] = self::cloneValue($item);
					}
				}
			} catch (\Exception $e) {
				error_log("TagCache mGet failed: " . $e->getMessage());
				// Fill misses with null
				foreach ($misses as $m) {
					$result[$m] = null;
				}
			}
		}

		// Preserve original order
		$ordered = [];
		foreach ($keys as $k) { 
			$ordered[$k] = $result[$k] ?? null; 
		}
		return $ordered;
	}

	/**
	 * Put many values (disabled for safety).
	 * @param array<string,mixed> $values
	 * @param string[] $tags
	 */
	public static function putMany(array $values, array $tags = [], ?int $ttlMs = null): void
	{
		throw new \Exception("AroggaCache::putMany is disabled - use individual set() calls with tags");
	}

	/**
	 * Defensive clone helper used both for storage and return. Non-objects (including null)
	 * are returned unchanged. Objects are shallow-cloned; if cloning not supported, a
	 * serialize/unserialize deep copy is attempted. Final fallback returns original.
	 */
	private static function cloneValue(mixed $value): mixed
	{
		if (is_object($value)) {
			try {
				return clone $value;
			} catch (\Exception $e) {
				// If clone fails, try serialize/unserialize for deep copy
				try {
					return unserialize(serialize($value));
				} catch (\Exception $e2) {
					error_log("Clone and serialize failed for object: " . $e2->getMessage());
					return $value; // Return original as last resort
				}
			}
		}
		return $value;
	}
	
	/**
	 * Get client statistics for monitoring.
	 */
	public static function getStats(): array
	{
		try {
			return self::getClient()->stats();
		} catch (\Exception $e) {
			error_log("TagCache stats failed: " . $e->getMessage());
			return [];
		}
	}
	
	/**
	 * Check if TagCache is healthy.
	 */
	public static function isHealthy(): bool
	{
		try {
			$stats = self::getStats();
			return !empty($stats);
		} catch (\Exception $e) {
			return false;
		}
	}
}