<?php declare(strict_types=1);

namespace TagCache\Tests\Transport;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use TagCache\Config;
use TagCache\Transport\TcpTransport;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\ApiException;

/**
 * @covers \TagCache\Transport\TcpTransport
 */
class TcpTransportTest extends TestCase
{
    private Config $config;
    private TcpTransport $transport;
    
    protected function setUp(): void
    {
        $config = new \TagCache\Config([
            'mode' => 'tcp',
            'tcp' => [
                'host' => $_ENV['TAGCACHE_TCP_HOST'] ?? 'localhost',
                'port' => (int)($_ENV['TAGCACHE_TCP_PORT'] ?? 1984),
                'timeout_ms' => 5000,
                'pool_size' => 3,
            ],
        ]);
        $this->transport = new TcpTransport($config);
    }
    
    protected function tearDown(): void
    {
        $this->transport->close();
    }
    
    public function test_put_and_get(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Test put
        $result = $transport->put('test-key', 'test-value', 60);
        $this->assertTrue($result);

        // Test get - TCP transport returns array response
        $response = $transport->get('test-key');
        $this->assertIsArray($response);
        $this->assertArrayHasKey('value', $response);
        $this->assertSame('test-value', $response['value']);
    }
    
    public function test_get_non_existent(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // TCP transport now throws NotFoundException for non-existent keys (consistent with HTTP)
        $this->expectException(\TagCache\Exceptions\NotFoundException::class);
        $transport->get('non-existent-key');
    }
    
    public function test_connection_pooling(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Multiple operations should reuse connections
        for ($i = 0; $i < 5; $i++) {
            $transport->put("key$i", "value$i", 60);
        }

        // Verify values - TCP transport returns array responses
        for ($i = 0; $i < 5; $i++) {
            $response = $transport->get("key$i");
            $this->assertIsArray($response);
            $this->assertArrayHasKey('value', $response);
            $this->assertSame("value$i", $response['value']);
        }
    }
    
    public function test_bulk_operations(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Individual puts (bulkPut not implemented)
        $items = [
            'key0' => 'value0',
            'key1' => 'value1',
            'key2' => 'value2'
        ];

        foreach ($items as $key => $value) {
            $result = $transport->put($key, $value, 60);
            $this->assertTrue($result);
        }

        // Bulk get - TCP transport returns array responses
        $keys = array_keys($items);
        $results = $transport->bulkGet($keys);

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $results);
            $this->assertIsArray($results[$key]);
            $this->assertArrayHasKey('value', $results[$key]);
            $this->assertSame($items[$key], $results[$key]['value']);
        }
    }
    
    public function test_tag_operations(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Put with tags
        $tags = ['tcp-tag', 'test-tag'];
        $transport->put('tagged:tcp:1', 'tcp-value-1', 60, $tags);
        $transport->put('tagged:tcp:2', 'tcp-value-2', 60, $tags);

        // Get tagged keys - using correct method name
        $tagged_keys = $transport->getKeysByTag('tcp-tag');
        $this->assertIsArray($tagged_keys);
        
        // Check if keys are in the response (handling different response formats)
        $key_found = false;
        foreach ($tagged_keys as $key) {
            if (is_string($key) && (str_contains($key, 'tagged:tcp:1') || str_contains($key, 'tagged:tcp:2'))) {
                $key_found = true;
                break;
            }
        }
        $this->assertTrue($key_found, 'Tagged keys not found in response');

        // Invalidate by tag - using correct method name
        $result = $transport->invalidateTags(['tcp-tag']);
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }
    
    public function test_stats(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        $stats = $transport->getStats();
        $this->assertIsArray($stats);
        
        // TCP protocol may return different stat keys
        $this->assertTrue(count($stats) > 0, 'Stats should not be empty');
        
        // Look for common stat keys that might be present
        $hasValidStats = isset($stats['total_keys']) || 
                        isset($stats['memory_usage']) || 
                        isset($stats['connections']) ||
                        isset($stats['hits']) ||
                        isset($stats['misses']);
        
        $this->assertTrue($hasValidStats, 'Stats should contain at least one valid metric');
    }
    
    public function test_health(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Health check is now supported as a connectivity test
        $result = $transport->health();
        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
        $this->assertEquals('tcp', $result['transport']);
    }
    
    public function test_connection_failure(): void
    {
        // Use invalid port to simulate connection failure
        $config = new Config([
            'mode' => 'tcp',
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 9999, // Invalid port
                'timeout_ms' => 1000,
                'pool_size' => 1,
            ],
            'username' => 'testuser',
            'password' => 'testpass',
        ]);
        
        $transport = new TcpTransport($config);

        // TCP transport throws ApiException for connection errors
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/TCP connect error/');
        $transport->get('test-key');
    }
    
    public function test_protocol_framing(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Test with large data
        $large_value = str_repeat('A', 10000);
        $transport->put('large-key', $large_value, 60);

        $response = $transport->get('large-key');
        $this->assertIsArray($response);
        $this->assertArrayHasKey('value', $response);
        $this->assertSame($large_value, $response['value']);
    }
    
    public function test_search(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Add some test data
        $transport->put('search:item:1', 'value1', 60, ['searchable']);
        $transport->put('search:item:2', 'value2', 60, ['searchable']);

        // Search functionality is not supported over TCP transport
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('search not supported over TCP transport');
        
        $searchParams = ['pattern' => 'search:*', 'limit' => 10];
        $transport->search($searchParams);
    }

    public function test_advanced_tag_operations(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Put items with multiple tags
        $transport->put('multi:tag:1', 'value1', 60, ['tag1', 'tag2', 'common']);
        $transport->put('multi:tag:2', 'value2', 60, ['tag2', 'tag3', 'common']);
        $transport->put('multi:tag:3', 'value3', 60, ['tag1', 'tag3', 'common']);

        // Test invalidating by multiple tags
        $result = $transport->invalidateTags(['tag1', 'tag2'], 'any');
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);

        // Test bulk delete
        $keys = ['multi:tag:1', 'multi:tag:2', 'multi:tag:3'];
        $deletedCount = $transport->bulkDelete($keys);
        $this->assertIsInt($deletedCount);
        $this->assertGreaterThanOrEqual(0, $deletedCount);
    }

    public function test_list_keys(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Add some test data
        $transport->put('list:item:1', 'value1', 60);
        $transport->put('list:item:2', 'value2', 60);
        $transport->put('list:item:3', 'value3', 60);

        // List functionality is not supported over TCP transport
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('list not supported over TCP transport');
        
        $transport->list(10);
    }

    public function test_flush_cache(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Add some test data
        $transport->put('flush:test:1', 'value1', 60);
        $transport->put('flush:test:2', 'value2', 60);

        // Flush the cache
        $result = $transport->flush();
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);

        // Verify data is gone
        $this->expectException(NotFoundException::class);
        $transport->get('flush:test:1');
    }

    public function test_auth_operations(): void
    {
        $transport = new TcpTransport($this->createMockConfig());

        // Setup required is not supported over TCP transport
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('setupRequired not supported over TCP transport');
        
        $transport->setupRequired();
    }

    private function createMockConfig(): Config
    {
        return new Config([
            'mode' => 'tcp',
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 1984,
                'timeout_ms' => 5000,
                'pool_size' => 3,
            ],
            'username' => 'testuser',
            'password' => 'testpass',
            'retry_attempts' => 3,
            'retry_delay_ms' => 100,
        ]);
    }
}
