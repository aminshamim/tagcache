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
        $this->config = new Config([
            'base_url' => 'http://localhost:3030',
            'timeout_ms' => 5000,
            'max_retries' => 3,
        ]);
        $this->transport = new HttpTransport($this->config);
    }
    
    public function testPutAndGet(): void
    {
        $key = 'http:test:' . uniqid();
        $value = 'test-value';
        $tags = ['http', 'test'];
        
        // Put
        $result = $this->transport->put($key, $value, $tags, 300);
        $this->assertTrue($result);
        
        // Get
        $result = $this->transport->get($key);
        $this->assertSame($value, $result);
        
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
            $this->assertTrue($this->transport->put($key, "value$i", ['bulk'], 300));
        }
        
        // Bulk get
        $results = $this->transport->bulkGet($keys);
        $this->assertCount(3, $results);
        $this->assertSame('value0', $results[$keys[0]]);
        $this->assertSame('value1', $results[$keys[1]]);
        $this->assertSame('value2', $results[$keys[2]]);
        
        // Bulk delete
        $this->assertTrue($this->transport->bulkDelete($keys));
        
        // Verify deletion
        $results = $this->transport->bulkGet($keys);
        $this->assertEmpty($results);
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
            $this->assertTrue($this->transport->put($key, 'value', [$tag], 300));
        }
        
        // Get keys by tag
        $result = $this->transport->getKeysByTag($tag);
        foreach ($keys as $key) {
            $this->assertContains($key, $result);
        }
        
        // Cleanup
        $this->assertTrue($this->transport->invalidateByTag($tag));
    }
    
    public function testInvalidateByTag(): void
    {
        $tag = 'invalidate-tag-' . uniqid();
        $key = 'invalidate:test:' . uniqid();
        
        // Put tagged key
        $this->assertTrue($this->transport->put($key, 'value', [$tag], 300));
        $this->assertSame('value', $this->transport->get($key));
        
        // Invalidate by tag
        $this->assertTrue($this->transport->invalidateByTag($tag));
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->transport->get($key);
    }
    
    public function testInvalidateByKey(): void
    {
        $key = 'invalidate:key:' . uniqid();
        
        // Put key
        $this->assertTrue($this->transport->put($key, 'value', ['test'], 300));
        $this->assertSame('value', $this->transport->get($key));
        
        // Invalidate by key
        $this->assertTrue($this->transport->invalidateByKey($key));
        
        // Verify deletion
        $this->expectException(NotFoundException::class);
        $this->transport->get($key);
    }
    
    public function testStats(): void
    {
        $stats = $this->transport->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_keys', $stats);
        $this->assertArrayHasKey('total_memory_usage', $stats);
    }
    
    public function testHealth(): void
    {
        $health = $this->transport->health();
        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertSame('OK', $health['status']);
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
            $this->assertTrue($this->transport->put($key, $value, ['search'], 300));
        }
        
        // Search
        $results = $this->transport->search($prefix);
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(2, count($results));
        
        // Cleanup
        foreach (array_keys($keys) as $key) {
            $this->transport->delete($key);
        }
    }
    
    public function testFlush(): void
    {
        $key = 'flush:test:' . uniqid();
        
        // Put test data
        $this->assertTrue($this->transport->put($key, 'value', ['flush'], 300));
        $this->assertSame('value', $this->transport->get($key));
        
        // Flush cache
        $this->assertTrue($this->transport->flush());
        
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
        
        $this->expectException(ConnectionException::class);
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
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $duration = microtime(true) - $start;
            // Should have tried 3 times (initial + 2 retries) with exponential backoff
            // Minimum duration should be around 0.6s (0.1s + 0.2s + 0.3s delays)
            $this->assertGreaterThan(0.6, $duration);
        }
    }
}
