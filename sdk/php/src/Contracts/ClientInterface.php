<?php

namespace TagCache\Contracts;

use TagCache\Models\Item;

interface ClientInterface
{
    public function put(string $key, mixed $value, array $tags = [], ?int $ttlMs = null): bool;
    public function get(string $key): ?Item;
    public function delete(string $key): bool;
    public function invalidateKeys(array $keys): int;
    public function invalidateTags(array $tags, string $mode = 'any'): int;
    public function bulkGet(array $keys): array; // key => Item|null
    public function bulkDelete(array $keys): int;
    public function search(array $params): array; // [keys => ...]
    public function stats(): array;
    public function list(int $limit = 100): array; // of Item
    public function getOrSet(string $key, callable $producer, ?int $ttlMs = null, array $tags = []): Item;
    public function flush(): int; // clear all cache
    public function health(): array;
    public function login(string $username, string $password): string; // returns token
    public function rotateCredentials(): array; // new username/password
    public function setupRequired(): bool;
    /**
     * Convenience wrappers around /search for common tag queries, returning an array of Item
     */
    public function keysByTag(string $tag, ?int $limit = null): array;
    public function keysByTagsAny(array $tags, ?int $limit = null): array;
    public function keysByTagsAll(array $tags, ?int $limit = null): array;
}
