<?php declare(strict_types=1);

namespace TagCache\Models;

final class Item implements \JsonSerializable
{
    /**
     * @param array<string> $tags
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly ?int $ttl = null,
        public readonly array $tags = [],
        public readonly ?int $createdMs = null,
    ) {}
    
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            $data['value'],
            $data['ttl'] ?? null,
            $data['tags'] ?? [],
            $data['createdMs'] ?? null
        );
    }
    
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'ttl' => $this->ttl,
            'tags' => $this->tags,
        ];
    }
    
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    public function equals(Item $other): bool
    {
        return $this->key === $other->key &&
               $this->value === $other->value &&
               $this->ttl === $other->ttl &&
               $this->tags === $other->tags;
    }
    
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }
    
    public function isExpired(): bool
    {
        if ($this->ttl === null) {
            return false;
        }
        
        return time() > $this->ttl;
    }
}
