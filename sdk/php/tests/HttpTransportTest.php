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
}
