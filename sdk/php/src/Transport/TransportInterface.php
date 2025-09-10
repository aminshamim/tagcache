<?php

namespace TagCache\Transport;

interface TransportInterface
{
    /**
     * @param string[] $tags
     */
    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool;
    
    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array;
    
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
     * @return array<string, array<string, mixed>>
     */
    public function bulkGet(array $keys): array;
    
    /**
     * @param string[] $keys
     */
    public function bulkDelete(array $keys): int;
    
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function search(array $params): array;
    
    /**
     * @return array<string>
     */
    public function getKeysByTag(string $tag): array;
    
    /**
     * @return array<string, mixed>
     */
    public function stats(): array;
    
    /**
     * @return array<string, mixed>
     */
    public function list(int $limit = 100): array;
    
    public function flush(): int;
    
    /**
     * @return array<string, mixed>
     */
    public function health(): array;
    
    public function close(): void;
    
    /**
     * @return array<string, mixed>
     */
    public function rotateCredentials(): array;

    public function login(string $username, string $password): bool;

    public function setupRequired(): bool;
}
