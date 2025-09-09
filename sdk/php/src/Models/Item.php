<?php

namespace TagCache\SDK\Models;

final class Item
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly ?int $ttlMs = null,
        public readonly array $tags = [],
        public readonly ?int $createdMs = null,
    ) {}
}
