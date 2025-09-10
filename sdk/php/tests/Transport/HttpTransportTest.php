<?php declare(strict_types=1);

namespace TagCache\Tests\Transport;

use PHPUnit\Framework\TestCase;
use TagCache\Config;
use TagCache\Transport\HttpTransport;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\TimeoutException;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\UnauthorizedException;
use TagCache\Exceptions\ServerException;

/**
 * @covers \TagCache\Transport\HttpTransport
 */
class HttpTransportTest extends TestCase
{
    private Config $config;
    private HttpTransport $transport;
    
    protected function setUp(): void
    {
        $config = new \TagCache\Config([
            'mode' => 'http',
            'http' => [
                'base_url' => $_ENV['TAGCACHE_HTTP_URL'] ?? 'http://localhost:8080',
                'timeout_ms' => 5000,
                'max_retries' => 2,
                'retry_delay_ms' => 100,
            ],
        ]);
        $this->transport = new HttpTransport($config);
    }
    
    public function testPutAndGet(): void
    {
        $key = 'http:test:' . uniqid();
        $value = 'test-value';
        $tags = ['http', 'test'];
        
        // Put
        $this->transport->put($key, $value, 300, $tags);
        // Put returns void, so just check no exception was thrown
        
        // Get
        $result = $this->transport->get($key);
        $this->assertSame($value, $result['value']);
        
        // Delete
        $result = $this->transport->delete($key);
        $this->assertTrue($result);
    }
    
    public function testGetNonExistent(): void
    {
        $this->expectException(NotFoundException::class);
        $this->transport->get('non-existent-' . uniqid());
    }
    
    public function testBulkOperations(): void
    {
        $keys = [
            'bulk:http:1:' . uniqid(),
            'bulk:http:2:' . uniqid(),
            'bulk:http:3:' . uniqid(),
        ];
        
        // Put test data
        foreach ($keys as $i => $key) {
            $this->assertTrue($this->transport->put($key, "value$i", 300, ['bulk']));
        }
        
        // Bulk get
        $results = $this->transport->bulkGet($keys);
        $this->assertCount(3, $results);
        $this->assertSame('value0', $results[$keys[0]]['value']);
        $this->assertSame('value1', $results[$keys[1]]['value']);
        $this->assertSame('value2', $results[$keys[2]]['value']);
        
        // Bulk delete
        $this->assertGreaterThan(0, $this->transport->bulkDelete($keys));
        
        // Verify deletion
        $results = $this->transport->bulkGet($keys);
        foreach ($results as $result) {
            $this->assertNull($result);
        }
    }
    
    public function testGetKeysByTag(): void
    {
        $tag = 'http-tag-' . uniqid();
        $keys = [
            'tagged:1:' . uniqid(),
            'tagged:2:' . uniqid(),
        ];
        
        // Put tagged keys
        foreach ($keys as $key) {
            $this->assertTrue($this->transport->put($key, 'value', 300, [$tag]));
        }
        
        // Get keys by tag
        $actualKeys = $this->transport->getKeysByTag($tag);
        foreach ($keys as $key) {
            $this->assertContains($key, $actualKeys);
        }
        
        // Cleanup
        $this->assertGreaterThan(0, $this->transport->invalidateTags([$tag]));
    }
    
    public function testInvalidateByTag(): void
    {
        $tag = 'invalidate-tag-' . uniqid();
        $key = 'invalidate:test:' . uniqid();
        
        // Put tagged key
        $this->assertTrue($this->transport->put($key, 'value', 300, [$tag]));
        $this->assertSame('value', $this->transport->get($key)['value']);
        
        // Invalidate by tag
        $this->assertGreaterThan(0, $this->transport->invalidateTags([$tag]));
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->transport->get($key);
    }
    
    public function testInvalidateByKey(): void
    {
        $key = 'invalidate:key:' . uniqid();
        
        // Put key
        $this->assertTrue($this->transport->put($key, 'value', 300, ['test']));
        $this->assertSame('value', $this->transport->get($key)['value']);
        
        // Invalidate by key
        $this->assertGreaterThan(0, $this->transport->invalidateKeys([$key]));
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->transport->get($key);
    }
    
    public function testStats(): void
    {
        $stats = $this->transport->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('items', $stats);
        $this->assertArrayHasKey('total_memory_usage', $stats);
    }
    
    public function testHealth(): void
    {
        $health = $this->transport->health();
        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertSame('ok', $health['status']);
    }
    
    public function testSearch(): void
    {
        $prefix = 'search:http:' . uniqid() . ':';
        $keys = [
            $prefix . 'test1' => 'value1',
            $prefix . 'test2' => 'value2',
        ];
        
        // Put test data
        foreach ($keys as $key => $value) {
            $this->assertTrue($this->transport->put($key, $value, 300, ['search']));
        }
        
        // Search
        $results = $this->transport->search(['prefix' => $prefix]);
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(2, count($results['keys'] ?? []));
        
        // Cleanup
        foreach (array_keys($keys) as $key) {
            $this->transport->delete($key);
        }
    }
    
    public function testFlush(): void
    {
        $key = 'flush:test:' . uniqid();
        
        // Put test data
        $this->assertTrue($this->transport->put($key, 'value', 300, ['flush']));
        $this->assertSame('value', $this->transport->get($key)['value']);
        
        // Flush cache
        $this->assertGreaterThan(0, $this->transport->flush());
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->transport->get($key);
    }
    
    public function testConnectionTimeout(): void
    {
        // Create transport with invalid host to test timeout
        $config = new Config([
            'base_url' => 'http://10.255.255.1:3030', // non-routable IP
            'timeout_ms' => 1000,
            'max_retries' => 0,
        ]);
        $transport = new HttpTransport($config);
        
        $this->expectException(TimeoutException::class);
        $transport->get('test-key');
    }
    
    public function testRetryLogic(): void
    {
        // Create transport with invalid host but retries enabled
        $config = new Config([
            'base_url' => 'http://10.255.255.1:3030', // non-routable IP
            'timeout_ms' => 500,
            'max_retries' => 2,
        ]);
        $transport = new HttpTransport($config);
        
        $start = microtime(true);
        
        try {
            $transport->get('test-key');
            $this->fail('Expected TimeoutException');
        } catch (TimeoutException $e) {
            $duration = microtime(true) - $start;
            // Should have tried 3 times (initial + 2 retries) with exponential backoff
            // Minimum duration should be around 0.5s (timeout per request)
            $this->assertGreaterThan(0.5, $duration);
        }
    }
}
