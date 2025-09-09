<?php declare(strict_types=1);

namespace TagCache\Tests;

use PHPUnit\Framework\TestCase;
use TagCache\Client;
use TagCache\Config;
use TagCache\Transport\HttpTransport;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\ApiException;

/**
 * @covers \TagCache\Client
 */
class ClientTest extends TestCase
{
    private Client $client;
    private Config $config;
    
    protected function setUp(): void
    {
        $this->config = new Config([
            'transport' => HttpTransport::class,
            'base_url' => 'http://localhost:3030',
            'timeout_ms' => 5000,
        ]);
        $this->client = new Client($this->config);
    }
    
    public function testPutAndGet(): void
    {
        $key = 'test:key:' . uniqid();
        $value = 'test-value-' . time();
        $tags = ['test', 'unit'];
        
        // Put
        $this->assertTrue($this->client->put($key, $value, $tags, 300));
        
        // Get
        $result = $this->client->get($key);
        $this->assertSame($value, $result);
        
        // Cleanup
        $this->assertTrue($this->client->delete($key));
    }
    
    public function testGetNonExistent(): void
    {
        $this->expectException(NotFoundException::class);
        $this->client->get('non-existent-key-' . uniqid());
    }
    
    public function testDeleteNonExistent(): void
    {
        $this->assertFalse($this->client->delete('non-existent-key-' . uniqid()));
    }
    
    public function testPutWithTags(): void
    {
        $key1 = 'test:tag1:' . uniqid();
        $key2 = 'test:tag2:' . uniqid();
        $value = 'tagged-value';
        $tag = 'tag-' . uniqid();
        
        // Put with tags
        $this->assertTrue($this->client->put($key1, $value, [$tag], 300));
        $this->assertTrue($this->client->put($key2, $value, [$tag], 300));
        
        // Get keys by tag
        $keys = $this->client->getKeysByTag($tag);
        $this->assertContains($key1, $keys);
        $this->assertContains($key2, $keys);
        $this->assertCount(2, $keys);
        
        // Invalidate by tag
        $this->assertTrue($this->client->invalidateByTag($tag));
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->client->get($key1);
    }
    
    public function testBulkOperations(): void
    {
        $keys = [
            'bulk:1:' . uniqid() => 'value1',
            'bulk:2:' . uniqid() => 'value2',
            'bulk:3:' . uniqid() => 'value3',
        ];
        
        // Put all keys
        foreach ($keys as $key => $value) {
            $this->assertTrue($this->client->put($key, $value, ['bulk'], 300));
        }
        
        // Bulk get
        $results = $this->client->bulkGet(array_keys($keys));
        $this->assertCount(3, $results);
        foreach ($keys as $key => $value) {
            $this->assertArrayHasKey($key, $results);
            $this->assertSame($value, $results[$key]);
        }
        
        // Bulk delete
        $this->assertTrue($this->client->bulkDelete(array_keys($keys)));
        
        // Verify deletion
        $results = $this->client->bulkGet(array_keys($keys));
        $this->assertEmpty($results);
    }
    
    public function testStats(): void
    {
        $stats = $this->client->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_keys', $stats);
        $this->assertArrayHasKey('total_memory_usage', $stats);
    }
    
    public function testHealth(): void
    {
        $health = $this->client->health();
        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertSame('OK', $health['status']);
    }
    
    public function testSearch(): void
    {
        $prefix = 'search:test:' . uniqid() . ':';
        $keys = [
            $prefix . 'apple' => 'fruit',
            $prefix . 'application' => 'software',
            $prefix . 'banana' => 'fruit',
        ];
        
        // Put test data
        foreach ($keys as $key => $value) {
            $this->assertTrue($this->client->put($key, $value, ['search'], 300));
        }
        
        // Search by prefix
        $results = $this->client->search($prefix);
        $this->assertGreaterThanOrEqual(3, count($results));
        
        // Search with pattern
        $results = $this->client->search($prefix . 'app*');
        $foundKeys = array_column($results, 'key');
        $this->assertContains($prefix . 'apple', $foundKeys);
        $this->assertContains($prefix . 'application', $foundKeys);
        
        // Cleanup
        foreach (array_keys($keys) as $key) {
            $this->client->delete($key);
        }
    }
    
    public function testTagHelpers(): void
    {
        $key = 'helper:test:' . uniqid();
        $tag = 'helper-tag-' . uniqid();
        
        // Put with tag
        $this->assertTrue($this->client->putWithTag($key, 'value', $tag, 300));
        
        // Verify key exists
        $this->assertSame('value', $this->client->get($key));
        
        // Get keys by tag
        $keys = $this->client->getKeysByTag($tag);
        $this->assertContains($key, $keys);
        
        // Delete by tag
        $this->assertTrue($this->client->deleteByTag($tag));
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->client->get($key);
    }
    
    public function testInvalidateByKey(): void
    {
        $key = 'invalidate:test:' . uniqid();
        $value = 'test-value';
        
        // Put key
        $this->assertTrue($this->client->put($key, $value, ['invalidate'], 300));
        $this->assertSame($value, $this->client->get($key));
        
        // Invalidate by key
        $this->assertTrue($this->client->invalidateByKey($key));
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->client->get($key);
    }
}
