<?php declare(strict_types=1);

namespace TagCache\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TagCache\Client;
use TagCache\Config;
use TagCache\Transport\HttpTransport;
use TagCache\Transport\TcpTransport;

/**
 * Integration tests against live TagCache server
 * 
 * @group integration
 */
class LiveServerTest extends TestCase
{
    private static bool $serverAvailable = false;
    
    public static function setUpBeforeClass(): void
    {
        // Check if server is running
        $ch = curl_init('http://localhost:3030/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        self::$serverAvailable = ($code === 200);
        
        if (!self::$serverAvailable) {
            static::markTestSkipped('TagCache server not available at localhost:3030');
        }
    }
    
    protected function setUp(): void
    {
        if (!self::$serverAvailable) {
            $this->markTestSkipped('TagCache server not available');
        }
    }
    
    public function httpClientProvider(): array
    {
        return [
            'HTTP Transport' => [new Client(new Config([
                'transport' => HttpTransport::class,
                'base_url' => 'http://localhost:3030',
                'timeout_ms' => 5000,
            ]))],
        ];
    }
    
    public function tcpClientProvider(): array
    {
        // Check if TCP port is available
        $socket = @fsockopen('127.0.0.1', 3031, $errno, $errstr, 2);
        if (!$socket) {
            return [];
        }
        fclose($socket);
        
        return [
            'TCP Transport' => [new Client(new Config([
                'transport' => TcpTransport::class,
                'host' => '127.0.0.1',
                'port' => 3031,
                'timeout_ms' => 5000,
            ]))],
        ];
    }
    
    public function allClientProvider(): array
    {
        return array_merge($this->httpClientProvider(), $this->tcpClientProvider());
    }
    
    /**
     * @dataProvider allClientProvider
     */
    public function testFullWorkflow(Client $client): void
    {
        $testId = uniqid();
        $key1 = "integration:test1:$testId";
        $key2 = "integration:test2:$testId";
        $tag = "tag:$testId";
        
        // 1. Put operations
        $this->assertTrue($client->put($key1, 'value1', [$tag, 'test'], 300));
        $this->assertTrue($client->put($key2, 'value2', [$tag, 'test'], 300));
        
        // 2. Get operations
        $this->assertSame('value1', $client->get($key1));
        $this->assertSame('value2', $client->get($key2));
        
        // 3. Bulk get
        $results = $client->bulkGet([$key1, $key2, 'non-existent']);
        $this->assertCount(2, $results);
        $this->assertSame('value1', $results[$key1]);
        $this->assertSame('value2', $results[$key2]);
        
        // 4. Tag operations
        $keys = $client->getKeysByTag($tag);
        $this->assertContains($key1, $keys);
        $this->assertContains($key2, $keys);
        
        // 5. Search
        $searchResults = $client->search("integration:test*:$testId");
        $this->assertGreaterThanOrEqual(2, count($searchResults));
        
        // 6. Stats
        $stats = $client->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_keys', $stats);
        $this->assertGreaterThan(0, $stats['total_keys']);
        
        // 7. Health check
        $health = $client->health();
        $this->assertSame('OK', $health['status']);
        
        // 8. Cleanup by tag
        $this->assertTrue($client->invalidateByTag($tag));
        
        // 9. Verify cleanup
        $keys = $client->getKeysByTag($tag);
        $this->assertEmpty($keys);
    }
    
    /**
     * @dataProvider allClientProvider
     */
    public function testLargePayloads(Client $client): void
    {
        $testId = uniqid();
        $key = "large:payload:$testId";
        
        // Test 1MB payload
        $largeValue = str_repeat('A', 1024 * 1024);
        
        $this->assertTrue($client->put($key, $largeValue, ['large'], 300));
        $this->assertSame($largeValue, $client->get($key));
        
        // Cleanup
        $this->assertTrue($client->delete($key));
    }
    
    /**
     * @dataProvider allClientProvider
     */
    public function testConcurrentOperations(Client $client): void
    {
        $testId = uniqid();
        $keys = [];
        
        // Create multiple keys concurrently
        for ($i = 0; $i < 20; $i++) {
            $key = "concurrent:$i:$testId";
            $keys[] = $key;
            $this->assertTrue($client->put($key, "value$i", ['concurrent'], 300));
        }
        
        // Verify all keys exist
        $results = $client->bulkGet($keys);
        $this->assertCount(20, $results);
        
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame("value$i", $results[$keys[$i]]);
        }
        
        // Bulk cleanup
        $this->assertTrue($client->bulkDelete($keys));
        
        // Verify cleanup
        $results = $client->bulkGet($keys);
        $this->assertEmpty($results);
    }
    
    /**
     * @dataProvider allClientProvider
     */
    public function testTagInvalidation(Client $client): void
    {
        $testId = uniqid();
        $sharedTag = "shared:$testId";
        $uniqueTag1 = "unique1:$testId";
        $uniqueTag2 = "unique2:$testId";
        
        $key1 = "tag:test1:$testId";
        $key2 = "tag:test2:$testId";
        $key3 = "tag:test3:$testId";
        
        // Create keys with overlapping tags
        $this->assertTrue($client->put($key1, 'value1', [$sharedTag, $uniqueTag1], 300));
        $this->assertTrue($client->put($key2, 'value2', [$sharedTag, $uniqueTag2], 300));
        $this->assertTrue($client->put($key3, 'value3', [$uniqueTag1], 300));
        
        // Verify setup
        $this->assertCount(2, $client->getKeysByTag($sharedTag));
        $this->assertCount(2, $client->getKeysByTag($uniqueTag1));
        $this->assertCount(1, $client->getKeysByTag($uniqueTag2));
        
        // Invalidate by shared tag
        $this->assertTrue($client->invalidateByTag($sharedTag));
        
        // Verify partial invalidation
        $this->assertEmpty($client->getKeysByTag($sharedTag));
        $this->assertCount(1, $client->getKeysByTag($uniqueTag1)); // key3 should remain
        $this->assertEmpty($client->getKeysByTag($uniqueTag2));
        
        // Cleanup remaining
        $this->assertTrue($client->delete($key3));
    }
    
    /**
     * @dataProvider httpClientProvider
     */
    public function testHttpSpecificFeatures(Client $client): void
    {
        // Test flush operation (HTTP only feature in this test)
        $testKey = 'flush:test:' . uniqid();
        
        $this->assertTrue($client->put($testKey, 'value', ['flush'], 300));
        $this->assertSame('value', $client->get($testKey));
        
        // Note: Flush affects entire cache, so we test it exists first
        $stats = $client->getStats();
        $keysBefore = $stats['total_keys'];
        $this->assertGreaterThan(0, $keysBefore);
        
        // Flush and verify
        $this->assertTrue($client->flush());
        
        $stats = $client->getStats();
        $this->assertSame(0, $stats['total_keys']);
    }
}
