<?php

namespace TagCache\Tests;

use PHPUnit\Framework\TestCase;
use TagCache\Config;
use TagCache\Transport\TcpTransport;
use TagCache\Exceptions\ApiException;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\ConfigurationException;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\TimeoutException;

/**
 * Test suite for TcpTransport enhancements
 * 
 * These tests verify the robust, high-performance improvements to TcpTransport
 * including enhanced connection management, serialization, error handling, and retry logic.
 */
class TcpTransportTest extends TestCase
{
    private TcpTransport $transport;
    private Config $config;
    
    protected function setUp(): void
    {
        $this->config = new Config([
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 1984,
                'timeout_ms' => 5000,
                'connect_timeout_ms' => 3000,
                'pool_size' => 4,
                'max_retries' => 2,
                'retry_delay_ms' => 100,
                'tcp_nodelay' => true,
                'keep_alive' => true,
                'keep_alive_interval' => 30
            ],
            'cache' => [
                'serializer' => 'auto',
                'default_ttl_ms' => 30000
            ]
        ]);
        
        $this->transport = new TcpTransport($this->config);
    }
    
    protected function tearDown(): void
    {
        $this->transport->close();
    }
    
    public function testBuilds(): void
    {
        $t = new TcpTransport(new Config(['tcp'=>['host'=>'127.0.0.1','port'=>1984,'timeout_ms'=>500]]));
        $this->assertInstanceOf(TcpTransport::class, $t);
        $t->close();
    }
    
    public function testConfigurationValidation(): void
    {
        // Test valid configuration
        $config = new Config([
            'tcp' => ['host' => '127.0.0.1', 'port' => 1984],
            'cache' => ['serializer' => 'native']
        ]);
        
        $transport = new TcpTransport($config);
        $this->assertInstanceOf(TcpTransport::class, $transport);
        $transport->close();
    }
    
    public function testInvalidSerializerConfiguration(): void
    {
        // Skip if igbinary is actually loaded
        if (extension_loaded('igbinary')) {
            $this->markTestSkipped('igbinary extension is loaded');
        }
        
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('igbinary serializer configured but igbinary extension not loaded');
        
        $config = new Config([
            'tcp' => ['host' => '127.0.0.1', 'port' => 1984],
            'cache' => ['serializer' => 'igbinary']
        ]);
        
        new TcpTransport($config);
    }
    
    public function testConnectionFailureWithRetry(): void
    {
        // Use invalid port to trigger connection failure
        $config = new Config([
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 99999, // Invalid port
                'timeout_ms' => 1000,
                'connect_timeout_ms' => 500,
                'max_retries' => 1,
                'retry_delay_ms' => 50
            ],
            'cache' => ['serializer' => 'native']
        ]);
        
        $transport = new TcpTransport($config);
        
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to establish TCP connection');
        
        $transport->put('test_key', 'test_value');
        $transport->close();
    }
    
    public function testHealthCheckWithConnectionDetails(): void
    {
        try {
            $health = $this->transport->health();
            
            $this->assertEquals('ok', $health['status']);
            $this->assertEquals('tcp', $health['transport']);
            $this->assertEquals('127.0.0.1', $health['host']);
            $this->assertEquals(1984, $health['port']);
            $this->assertArrayHasKey('pool_size', $health);
            $this->assertArrayHasKey('healthy_connections', $health);
            $this->assertArrayHasKey('connection_failures', $health);
            $this->assertArrayHasKey('timeout_ms', $health);
            $this->assertArrayHasKey('max_retries', $health);
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testEnhancedSerialization(): void
    {
        try {
            $testData = [
                'string' => 'Hello World',
                'integer' => 12345,
                'float' => 123.45,
                'boolean' => true,
                'null' => null,
                'array' => [1, 2, 3, 'nested'],
                'object' => ['key' => 'value', 'nested' => ['deep' => 'value']]
            ];
            
            // Test with array data
            $this->transport->put('test_serialization', $testData, 60000, ['test', 'serialization']);
            $result = $this->transport->get('test_serialization');
            
            $this->assertIsArray($result);
            $this->assertArrayHasKey('value', $result);
            $this->assertEquals($testData, $result['value']);
            
            // Clean up
            $this->transport->delete('test_serialization');
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testEnhancedPutWithDefaultTtl(): void
    {
        try {
            // Test put without TTL (should use default)
            $result = $this->transport->put('test_default_ttl', 'test_value', null, ['test']);
            $this->assertTrue($result);
            
            // Clean up
            $this->transport->delete('test_default_ttl');
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testEnhancedGetWithDeserialization(): void
    {
        try {
            $originalData = ['complex' => 'data', 'with' => ['nested' => 'structure']];
            
            $this->transport->put('test_get', $originalData, 60000);
            $result = $this->transport->get('test_get');
            
            $this->assertIsArray($result);
            $this->assertArrayHasKey('value', $result);
            $this->assertEquals($originalData, $result['value']);
            
            // Clean up
            $this->transport->delete('test_get');
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testGetNotFound(): void
    {
        try {
            $this->expectException(NotFoundException::class);
            $this->expectExceptionMessage('Key not found: nonexistent_key');
            
            $this->transport->get('nonexistent_key');
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testEnhancedDelete(): void
    {
        try {
            // Test successful delete
            $this->transport->put('test_delete', 'value', 60000);
            $result = $this->transport->delete('test_delete');
            $this->assertTrue($result);
            
            // Test delete of non-existent key
            $result = $this->transport->delete('nonexistent_delete_key');
            $this->assertFalse($result); // Should return false, not throw exception
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testBulkOperations(): void
    {
        try {
            $keys = ['bulk1', 'bulk2', 'bulk3'];
            $values = ['value1', 'value2', 'value3'];
            
            // Setup test data
            for ($i = 0; $i < count($keys); $i++) {
                $this->transport->put($keys[$i], $values[$i], 60000, ['bulk']);
            }
            
            // Test bulk get
            $result = $this->transport->bulkGet($keys);
            $this->assertCount(3, $result);
            
            foreach ($keys as $i => $key) {
                $this->assertArrayHasKey($key, $result);
                $this->assertEquals($values[$i], $result[$key]['value']);
            }
            
            // Test bulk delete
            $deleteCount = $this->transport->bulkDelete($keys);
            $this->assertEquals(3, $deleteCount);
            
            // Verify deletion
            $emptyResult = $this->transport->bulkGet($keys);
            $this->assertEmpty($emptyResult);
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testTagOperations(): void
    {
        try {
            // Setup test data with tags
            $this->transport->put('tag_test1', 'value1', 60000, ['tag1', 'common']);
            $this->transport->put('tag_test2', 'value2', 60000, ['tag2', 'common']);
            $this->transport->put('tag_test3', 'value3', 60000, ['tag1', 'tag2']);
            
            // Test get keys by tag
            $tag1Keys = $this->transport->getKeysByTag('tag1');
            $this->assertContains('tag_test1', $tag1Keys);
            $this->assertContains('tag_test3', $tag1Keys);
            
            $commonKeys = $this->transport->getKeysByTag('common');
            $this->assertContains('tag_test1', $commonKeys);
            $this->assertContains('tag_test2', $commonKeys);
            
            // Test tag invalidation
            $invalidated = $this->transport->invalidateTags(['common']);
            $this->assertGreaterThan(0, $invalidated);
            
            // Verify invalidation
            $remainingCommonKeys = $this->transport->getKeysByTag('common');
            $this->assertEmpty($remainingCommonKeys);
            
            // Clean up remaining keys
            $this->transport->delete('tag_test3');
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testEnhancedSearch(): void
    {
        try {
            // Setup test data
            $this->transport->put('search1', 'value1', 60000, ['red', 'fruit']);
            $this->transport->put('search2', 'value2', 60000, ['red', 'vegetable']);
            $this->transport->put('search3', 'value3', 60000, ['blue', 'fruit']);
            
            // Test tag_any search
            $anyResult = $this->transport->search(['tag_any' => ['red', 'blue']]);
            $this->assertCount(3, $anyResult);
            
            // Test tag_all search  
            $allResult = $this->transport->search(['tag_all' => ['red', 'fruit']]);
            $this->assertCount(1, $allResult);
            $this->assertEquals('search1', $allResult[0]['key']);
            
            // Clean up
            $this->transport->bulkDelete(['search1', 'search2', 'search3']);
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testUnsupportedOperations(): void
    {
        // Test operations that are not supported over TCP
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('list not supported over TCP transport');
        $this->transport->list();
    }
    
    public function testAuthenticationNotSupported(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('login not supported over TCP transport');
        $this->transport->login('user', 'pass');
    }
    
    public function testEnhancedStats(): void
    {
        try {
            $stats = $this->transport->stats();
            
            // Verify required fields
            $this->assertArrayHasKey('hits', $stats);
            $this->assertArrayHasKey('misses', $stats);
            $this->assertArrayHasKey('puts', $stats);
            $this->assertArrayHasKey('invalidations', $stats);
            $this->assertArrayHasKey('hit_ratio', $stats);
            
            // Verify enhanced fields
            $this->assertArrayHasKey('transport', $stats);
            $this->assertEquals('tcp', $stats['transport']);
            $this->assertArrayHasKey('pool_size', $stats);
            $this->assertArrayHasKey('pool_healthy', $stats);
            $this->assertArrayHasKey('connection_failures', $stats);
            
            // Test getStats alias
            $aliasStats = $this->transport->getStats();
            $this->assertEquals($stats, $aliasStats);
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testFlushOperation(): void
    {
        try {
            // Add some test data
            $this->transport->put('flush_test1', 'value1', 60000);
            $this->transport->put('flush_test2', 'value2', 60000);
            
            // Flush all
            $flushedCount = $this->transport->flush();
            $this->assertGreaterThanOrEqual(2, $flushedCount);
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
    
    public function testComplexSearchNotSupported(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Complex search queries not supported over TCP transport');
        
        $this->transport->search(['q' => 'some query']);
    }
    
    public function testInvalidateTagsAllModeNotSupported(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('invalidateTags with mode="all" not supported over TCP transport');
        
        $this->transport->invalidateTags(['tag1', 'tag2'], 'all');
    }
    
    public function testConnectionPoolBehavior(): void
    {
        try {
            // Perform multiple operations to test connection reuse
            for ($i = 0; $i < 10; $i++) {
                $this->transport->put("pool_test_{$i}", "value_{$i}", 60000);
            }
            
            // Get health to verify pool status
            $health = $this->transport->health();
            $this->assertGreaterThan(0, $health['pool_size']);
            $this->assertGreaterThan(0, $health['healthy_connections']);
            
            // Clean up
            for ($i = 0; $i < 10; $i++) {
                $this->transport->delete("pool_test_{$i}");
            }
            
        } catch (ConnectionException $e) {
            $this->markTestSkipped('TagCache server not running on localhost:1984');
        }
    }
}
