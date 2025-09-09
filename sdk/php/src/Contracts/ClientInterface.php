<?php

namespace TagCache\Contracts;

use TagCache\Models\Item;

interface ClientInterface
{
    /**
     * @param string[] $tags
     */
    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool;
    
    public function get(string $key): mixed;
    
    public function delete(string $key): bool;
    
    /**
     * @param string[] $keys
     */
    public function invalidateKeys(array $keys): int;
    
    /**
     * @param string[] $tags
     */
    public function invalidateTags(array $tags, string $mode = 'any'): int;
    
    /**
     * @param string[] $keys
     * @return array<string, Item|null> key => Item|null
     */
    public function bulkGet(array $keys): array;
    
    /**
     * @param string[] $keys
     */
    public function bulkDelete(array $keys): int;
    
    /**
     * @param array<string, mixed>|string $params Search parameters or pattern string
     * @return array<string, mixed> [keys => ...]
     */
    public function search(array|string $params): array;
    
    /**
     * @return array<string, mixed>
     */
    public function stats(): array;
    
    /**
     * @return Item[] of Item
     */
    public function list(int $limit = 100): array;
    
    /**
     * @param string[] $tags
     */
    public function getOrSet(string $key, callable $producer, ?int $ttlMs = null, array $tags = []): Item;
    
    public function flush(): int; // clear all cache
    
    /**
     * @return array<string, mixed>
     */
    public function health(): array;
    
    public function login(string $username, string $password): bool; // returns success status
    
    /**
     * @return array<string, mixed> new username/password
     */
    public function rotateCredentials(): array;
    
    public function setupRequired(): bool;
    
    /**
     * @return string[]
     */
    public function keysByTag(string $tag, ?int $limit = null): array;
    
    /**
     * @param string[] $tags
     * @return string[]
     */
    public function keysByTagsAny(array $tags, ?int $limit = null): array;
    
    /**
     * @param string[] $tags
     * @return string[]
     */
    public function keysByTagsAll(array $tags, ?int $limit = null): array;
}
