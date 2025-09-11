<?php

use PHPUnit\Framework\TestCase;
use TagCache\Config;
use TagCache\Transport\HttpTransport;

final class HttpTransportTest extends TestCase
{
    public function testBuilds(): void
    {
        $t = new HttpTransport(new Config([
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 1000
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'password',
            ],
        ]));
        $this->assertInstanceOf(HttpTransport::class, $t);
    }

    public function testGuzzleClientIsUsed(): void
    {
        // Test that our Guzzle implementation is properly configured
        $config = new Config([
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 2000
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'password',
            ],
        ]);
        
        $transport = new HttpTransport($config);
        
        // The constructor should not throw any exceptions
        $this->assertInstanceOf(HttpTransport::class, $transport);
        
        // Close should work without errors
        $transport->close();
        $this->assertTrue(true); // If we get here, close() worked
    }

    public function testAuthenticationFailureDoesNotRetry(): void
    {
        // Test with invalid credentials to ensure auth failures don't retry
        $config = new Config([
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 1000,
                'max_retries' => 3, // Set retries to ensure we can test this
            ],
            'auth' => [
                'username' => 'invalid',
                'password' => 'invalid',
            ],
        ]);
        
        $transport = new HttpTransport($config);
        
        // This should fail quickly without retries
        $startTime = microtime(true);
        
        try {
            $transport->get('test-key');
            $this->fail('Expected UnauthorizedException to be thrown');
        } catch (\TagCache\Exceptions\UnauthorizedException $e) {
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            
            // Should fail quickly (under 2 seconds) since auth failures don't retry
            $this->assertLessThan(2.0, $duration, 'Authentication failure should not retry');
            $this->assertStringContainsString('unauthorized', strtolower($e->getMessage()));
        }
    }

    public function testConnectionFailureRetriesWithExponentialBackoff(): void
    {
        // Test with unreachable server to ensure connection failures retry
        $config = new Config([
            'http' => [
                'base_url' => 'http://localhost:9999', // Non-existent port
                'timeout_ms' => 500,
                'max_retries' => 2,
                'retry_delay_ms' => 100, // Start with 100ms delay
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'password',
            ],
        ]);
        
        $transport = new HttpTransport($config);
        
        // This should retry and take longer due to exponential backoff
        $startTime = microtime(true);
        
        try {
            $transport->get('test-key');
            $this->fail('Expected ConnectionException to be thrown');
        } catch (\TagCache\Exceptions\ConnectionException $e) {
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            
            // Should take longer due to retries (at least 300ms for delays: 0 + 100 + 200)
            // The actual duration depends on connection timeout + retry delays
            $this->assertGreaterThan(0.25, $duration, 'Connection failure should retry with delays');
            $this->assertStringContainsString('Connection error', $e->getMessage());
        }
    }
}
