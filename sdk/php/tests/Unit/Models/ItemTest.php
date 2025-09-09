<?php declare(strict_types=1);

namespace TagCache\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use TagCache\Models\Item;

/**
 * @covers \TagCache\Models\Item
 */
class ItemTest extends TestCase
{
    public function testConstructor(): void
    {
        $item = new Item('test_key', 'test_value', 3600, ['tag1', 'tag2']);
        
        $this->assertSame('test_key', $item->key);
        $this->assertSame('test_value', $item->value);
        $this->assertSame(['tag1', 'tag2'], $item->tags);
        $this->assertSame(3600, $item->ttl);
    }
    
    public function testFromArray(): void
    {
        $data = [
            'key' => 'array_key',
            'value' => 'array_value',
            'tags' => ['array_tag'],
            'ttl' => 1800,
        ];
        
        $item = Item::fromArray($data);
        
        $this->assertSame('array_key', $item->key);
        $this->assertSame('array_value', $item->value);
        $this->assertSame(['array_tag'], $item->tags);
        $this->assertSame(1800, $item->ttl);
    }
    
    public function testToArray(): void
    {
        $item = new Item('to_array_key', 'to_array_value', 7200, ['to_array_tag']);
        
        $array = $item->toArray();
        
        $expected = [
            'key' => 'to_array_key',
            'value' => 'to_array_value',
            'ttl' => 7200,
            'tags' => ['to_array_tag'],
        ];
        
        $this->assertSame($expected, $array);
    }
    
    public function testWithDefaultValues(): void
    {
        $item = new Item('minimal_key', 'minimal_value');
        
        $this->assertSame('minimal_key', $item->key);
        $this->assertSame('minimal_value', $item->value);
        $this->assertSame([], $item->tags);
        $this->assertNull($item->ttl);
    }
    
    public function testFromArrayWithMissingFields(): void
    {
        $data = [
            'key' => 'partial_key',
            'value' => 'partial_value',
            // No tags or ttl
        ];
        
        $item = Item::fromArray($data);
        
        $this->assertSame('partial_key', $item->key);
        $this->assertSame('partial_value', $item->value);
        $this->assertSame([], $item->tags);
        $this->assertNull($item->ttl);
    }
    
    public function testJsonSerialization(): void
    {
        $item = new Item('json_key', 'json_value', 900, ['json_tag']);
        
        $json = json_encode($item);
        $decoded = json_decode($json, true);
        
        $expected = [
            'key' => 'json_key',
            'value' => 'json_value',
            'ttl' => 900,
            'tags' => ['json_tag'],
        ];
        
        $this->assertSame($expected, $decoded);
    }
    
    public function testEquality(): void
    {
        $item1 = new Item('same_key', 'same_value', 600, ['same_tag']);
        $item2 = new Item('same_key', 'same_value', 600, ['same_tag']);
        $item3 = new Item('different_key', 'same_value', 600, ['same_tag']);
        
        $this->assertTrue($item1->equals($item2));
        $this->assertFalse($item1->equals($item3));
    }
    
    public function testHasTag(): void
    {
        $item = new Item('tagged_key', 'value', null, ['tag1', 'tag2', 'tag3']);
        
        $this->assertTrue($item->hasTag('tag1'));
        $this->assertTrue($item->hasTag('tag2'));
        $this->assertTrue($item->hasTag('tag3'));
        $this->assertFalse($item->hasTag('non_existent_tag'));
    }
    
    public function testIsExpired(): void
    {
        // Item with no TTL never expires
        $item1 = new Item('no_ttl', 'value');
        $this->assertFalse($item1->isExpired());
        
        // Item with future TTL not expired
        $item2 = new Item('future', 'value', time() + 3600, []);
        $this->assertFalse($item2->isExpired());
        
        // Item with past TTL is expired
        $item3 = new Item('past', 'value', time() - 3600, []);
        $this->assertTrue($item3->isExpired());
    }
}
