<?php declare(strict_types=1);

namespace TagCache\Tests\Transport;

use PHPUnit\Framework\TestCase;
use TagCache\Config;
use TagCache\Transport\TcpTransport;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\NotFoundException;

/**
 * @covers \TagCache\Transport\TcpTransport
 */
class TcpTransportTest extends TestCase
{
    private Config $config;
    private TcpTransport $transport;
    
    protected function setUp(): void
    {
        $this->config = new Config([
            'mode' => 'tcp',
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 3031,
                'timeout_ms' => 5000,
                'pool_size' => 5,
            ],
        ]);
        $this->transport = new TcpTransport($this->config);
    }
    
    protected function tearDown(): void
    {
        $this->transport->close();
    }
    
    public function testPutAndGet(): void
    {
        $key = 'tcp:test:' . uniqid();
        $value = 'test-value';
        $tags = ['tcp', 'test'];
        
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
        $this->transport->get('non-existent-tcp-' . uniqid());
    }
    
    public function testConnectionPooling(): void
    {
        $keys = [];
        $transport = $this->transport;
        
        // Create multiple concurrent-like operations
        for ($i = 0; $i < 10; $i++) {
            $key = "pool:test:$i:" . uniqid();
            $keys[] = $key;
            $this->assertTrue($transport->put($key, "value$i", 300, ['pool']));
        }
        
        // Verify all operations succeeded
        foreach ($keys as $i => $key) {
            $this->assertSame("value$i", $transport->get($key));
        }
        
        // Cleanup
        foreach ($keys as $key) {
            $transport->delete($key);
        }
    }
    
    public function testBulkOperations(): void
    {
        $keys = [
            'bulk:tcp:1:' . uniqid(),
            'bulk:tcp:2:' . uniqid(),
            'bulk:tcp:3:' . uniqid(),
        ];
        
        // Put test data
        foreach ($keys as $i => $key) {
            $this->assertTrue($this->transport->put($key, "value$i", 300, ['bulk']));
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
    
    public function testTagOperations(): void
    {
        $tag = 'tcp-tag-' . uniqid();
        $keys = [
            'tagged:tcp:1:' . uniqid(),
            'tagged:tcp:2:' . uniqid(),
        ];
        
        // Put tagged keys
        foreach ($keys as $key) {
            $this->assertTrue($this->transport->put($key, 'value', 300, [$tag]));
        }
        
        // Get keys by tag
        $result = $this->transport->getKeysByTag($tag);
        foreach ($keys as $key) {
            $this->assertContains($key, $result);
        }
        
        // Invalidate by tag
        $this->assertTrue($this->transport->invalidateByTag($tag));
        
        // Verify deletion
        $result = $this->transport->getKeysByTag($tag);
        $this->assertEmpty($result);
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
    
    public function testConnectionFailure(): void
    {
        // Create transport with invalid host
        $config = new Config([
            'host' => '127.0.0.1',
            'port' => 99999, // invalid port
            'timeout_ms' => 1000,
        ]);
        $transport = new TcpTransport($config);
        
        $this->expectException(ConnectionException::class);
        $transport->get('test-key');
    }
    
    public function testProtocolFraming(): void
    {
        $key = 'protocol:test:' . uniqid();
        $longValue = str_repeat('A', 10000); // Test large payload
        
        // Put large value
        $this->assertTrue($this->transport->put($key, $longValue, 300, ['protocol']));
        
        // Get large value
        $result = $this->transport->get($key);
        $this->assertSame($longValue, $result);
        
        // Cleanup
        $this->assertTrue($this->transport->delete($key));
    }
    
    public function testSearch(): void
    {
        $prefix = 'search:tcp:' . uniqid() . ':';
        $keys = [
            $prefix . 'alpha' => 'value1',
            $prefix . 'beta' => 'value2',
        ];
        
        // Put test data
        foreach ($keys as $key => $value) {
            $this->assertTrue($this->transport->put($key, $value, 300, ['search']));
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
}
