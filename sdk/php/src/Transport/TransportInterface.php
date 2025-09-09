<?php

namespace TagCache\SDK\Transport;

interface TransportInterface
{
    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): void;
    public function get(string $key): ?array; // assoc item
    public function delete(string $key): bool;
    public function invalidateKeys(array $keys): int;
    public function invalidateTags(array $tags, string $mode = 'any'): int;
    public function bulkGet(array $keys): array;
    public function bulkDelete(array $keys): int;
    public function search(array $params): array;
    public function stats(): array;
    public function list(int $limit = 100): array;
    public function flush(): int;
    public function health(): array;
    public function login(string $username, string $password): string;
    public function rotateCredentials(): array;
    public function setupRequired(): bool;
}
