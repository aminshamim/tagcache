<?php declare(strict_types=1);

namespace TagCache\Tests;

use PHPUnit\Framework\TestCase;
use TagCache\Client;
use TagCache\Config;
use TagCache\Transport\HttpTransport;
use TagCache\Exceptions\NotFoundException;

/**
 * Performance and stress tests for TagCache SDK
 * 
 * @group performance
 */
class PerformanceTest extends TestCase
{
    private Client $client;
    
    protected function setUp(): void
    {
        $this->client = new Client(new Config([
            'transport' => HttpTransport::class,
            'base_url' => 'http://localhost:3030',
            'timeout_ms' => 10000,
            'max_retries' => 1,
        ]));
        
        // Check if server is available
        try {
            $this->client->health();
        } catch (\Exception $e) {
            $this->markTestSkipped('TagCache server not available: ' . $e->getMessage());
        }
    }
    
    public function testBulkOperationPerformance(): void
    {
        $keyCount = 1000;
        $keys = [];
        $values = [];
        
        // Generate test data
        for ($i = 0; $i < $keyCount; $i++) {
            $key = "perf:bulk:$i:" . uniqid();
            $value = "value-$i-" . str_repeat('x', 100); // ~100 byte values
            $keys[] = $key;
            $values[$key] = $value;
        }
        
        // Measure individual puts
        $start = microtime(true);
        foreach ($values as $key => $value) {
            $this->client->put($key, $value, ['performance'], 300);
        }
        $individualTime = microtime(true) - $start;
        
        // Measure bulk get
        $start = microtime(true);
        $results = $this->client->bulkGet($keys);
        $bulkGetTime = microtime(true) - $start;
        
        // Verify results
        $this->assertCount($keyCount, $results);
        
        // Measure bulk delete
        $start = microtime(true);
        $this->client->bulkDelete($keys);
        $bulkDeleteTime = microtime(true) - $start;
        
        // Performance assertions (adjust thresholds based on environment)
        $this->assertLessThan(10.0, $individualTime, "Individual puts took too long: {$individualTime}s");
        $this->assertLessThan(2.0, $bulkGetTime, "Bulk get took too long: {$bulkGetTime}s");
        $this->assertLessThan(2.0, $bulkDeleteTime, "Bulk delete took too long: {$bulkDeleteTime}s");
        
        printf("Performance: %d puts=%.3fs, bulk_get=%.3fs, bulk_delete=%.3fs\n", 
            $keyCount, $individualTime, $bulkGetTime, $bulkDeleteTime);
    }
    
    public function testTagOperationPerformance(): void
    {
        $tagCount = 100;
        $keysPerTag = 50;
        $tags = [];
        $allKeys = [];
        
        // Create test data with multiple tags
        for ($t = 0; $t < $tagCount; $t++) {
            $tag = "perf-tag-$t-" . uniqid();
            $tags[] = $tag;
            
            for ($k = 0; $k < $keysPerTag; $k++) {
                $key = "perf:tag:$t:$k:" . uniqid();
                $allKeys[] = $key;
                $this->client->put($key, "value-$t-$k", [$tag, 'performance'], 300);
            }
        }
        
        // Measure tag queries
        $start = microtime(true);
        foreach ($tags as $tag) {
            $keys = $this->client->getKeysByTag($tag);
            $this->assertCount($keysPerTag, $keys);
        }
        $tagQueryTime = microtime(true) - $start;
        
        // Measure tag invalidation
        $start = microtime(true);
        foreach ($tags as $tag) {
            $this->client->invalidateByTag($tag);
        }
        $tagInvalidateTime = microtime(true) - $start;
        
        // Performance assertions
        $this->assertLessThan(5.0, $tagQueryTime, "Tag queries took too long: {$tagQueryTime}s");
        $this->assertLessThan(5.0, $tagInvalidateTime, "Tag invalidation took too long: {$tagInvalidateTime}s");
        
        printf("Tag performance: %d tags x %d keys, queries=%.3fs, invalidations=%.3fs\n", 
            $tagCount, $keysPerTag, $tagQueryTime, $tagInvalidateTime);
    }
    
    public function testSearchPerformance(): void
    {
        $prefixes = ['search:alpha:', 'search:beta:', 'search:gamma:'];
        $keysPerPrefix = 200;
        $testId = uniqid();
        
        // Create test data
        foreach ($prefixes as $prefix) {
            for ($i = 0; $i < $keysPerPrefix; $i++) {
                $key = $prefix . sprintf('%04d', $i) . ":$testId";
                $this->client->put($key, "value-$i", ['search-perf'], 300);
            }
        }
        
        // Measure search performance
        $start = microtime(true);
        foreach ($prefixes as $prefix) {
            $results = $this->client->search($prefix . "*:$testId");
            $this->assertGreaterThanOrEqual($keysPerPrefix, count($results));
        }
        $searchTime = microtime(true) - $start;
        
        // Cleanup
        $this->client->invalidateByTag('search-perf');
        
        // Performance assertion
        $this->assertLessThan(3.0, $searchTime, "Search took too long: {$searchTime}s");
        
        printf("Search performance: %d prefixes x %d keys, time=%.3fs\n", 
            count($prefixes), $keysPerPrefix, $searchTime);
    }
    
    public function testLargePayloadPerformance(): void
    {
        $sizes = [1024, 10240, 102400, 1048576]; // 1KB, 10KB, 100KB, 1MB
        $results = [];
        
        foreach ($sizes as $size) {
            $key = "large:perf:$size:" . uniqid();
            $value = str_repeat('A', $size);
            
            // Measure put
            $start = microtime(true);
            $this->client->put($key, $value, ['large-perf'], 300);
            $putTime = microtime(true) - $start;
            
            // Measure get
            $start = microtime(true);
            $retrieved = $this->client->get($key);
            $getTime = microtime(true) - $start;
            
            // Verify
            $this->assertSame($value, $retrieved);
            
            // Cleanup
            $this->client->delete($key);
            
            $results[] = [
                'size' => $size,
                'size_kb' => $size / 1024,
                'put_time' => $putTime,
                'get_time' => $getTime,
                'put_throughput_mbps' => ($size / 1048576) / $putTime,
                'get_throughput_mbps' => ($size / 1048576) / $getTime,
            ];
            
            // Performance assertions (adjust based on environment)
            $this->assertLessThan(2.0, $putTime, "Put of {$size} bytes took too long: {$putTime}s");
            $this->assertLessThan(2.0, $getTime, "Get of {$size} bytes took too long: {$getTime}s");
        }
        
        foreach ($results as $result) {
            printf("Payload %dKB: put=%.3fs (%.1fMB/s), get=%.3fs (%.1fMB/s)\n",
                (int)$result['size_kb'], $result['put_time'], $result['put_throughput_mbps'],
                $result['get_time'], $result['get_throughput_mbps']);
        }
    }
    
    public function testConnectionPoolingEfficiency(): void
    {
        $operations = 500;
        $start = microtime(true);
        
        // Perform many operations that should reuse connections
        for ($i = 0; $i < $operations; $i++) {
            $key = "pool:test:$i:" . uniqid();
            
            // Mix of operations
            $this->client->put($key, "value-$i", ['pool-test'], 60);
            $this->assertSame("value-$i", $this->client->get($key));
            $this->client->delete($key);
        }
        
        $totalTime = microtime(true) - $start;
        $opsPerSecond = $operations * 3 / $totalTime; // 3 ops per iteration
        
        // Performance assertion
        $this->assertGreaterThan(100, $opsPerSecond, 
            "Operations per second too low: {$opsPerSecond}");
        
        printf("Connection pooling: %d operations in %.3fs = %.1f ops/sec\n", 
            $operations * 3, $totalTime, $opsPerSecond);
    }
    
    public function testMemoryUsage(): void
    {
        $memBefore = memory_get_usage(true);
        
        // Perform operations that might leak memory
        for ($i = 0; $i < 1000; $i++) {
            $key = "memory:test:$i:" . uniqid();
            $value = str_repeat('x', 1000);
            
            $this->client->put($key, $value, ['memory-test'], 60);
            $this->client->get($key);
            $this->client->delete($key);
            
            // Force garbage collection every 100 iterations
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }
        
        gc_collect_cycles();
        $memAfter = memory_get_usage(true);
        $memIncrease = $memAfter - $memBefore;
        
        // Memory should not increase significantly (< 10MB)
        $this->assertLessThan(10 * 1048576, $memIncrease, 
            "Memory usage increased too much: " . ($memIncrease / 1048576) . "MB");
        
        printf("Memory usage: before=%dMB, after=%dMB, increase=%dMB\n", 
            $memBefore / 1048576, $memAfter / 1048576, $memIncrease / 1048576);
    }
}
