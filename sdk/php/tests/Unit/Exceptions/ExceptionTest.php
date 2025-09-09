<?php declare(strict_types=1);

namespace TagCache\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use TagCache\Exceptions\ApiException;
use TagCache\Exceptions\NotFoundException;
use TagCache\Exceptions\ConnectionException;
use TagCache\Exceptions\TimeoutException;
use TagCache\Exceptions\AuthenticationException;

/**
 * @covers \TagCache\Exceptions\ApiException
 * @covers \TagCache\Exceptions\NotFoundException
 * @covers \TagCache\Exceptions\ConnectionException
 * @covers \TagCache\Exceptions\TimeoutException
 * @covers \TagCache\Exceptions\AuthenticationException
 */
class ExceptionTest extends TestCase
{
    public function testApiExceptionBasic(): void
    {
        $exception = new ApiException('Test error', 500);
        
        $this->assertSame('Test error', $exception->getMessage());
        $this->assertSame(500, $exception->getCode());
        $this->assertInstanceOf(\Exception::class, $exception);
    }
    
    public function testApiExceptionWithPrevious(): void
    {
        $previous = new \RuntimeException('Previous error');
        $exception = new ApiException('API error', 400, $previous);
        
        $this->assertSame('API error', $exception->getMessage());
        $this->assertSame(400, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
    
    public function testNotFoundException(): void
    {
        $exception = new NotFoundException('Key not found');
        
        $this->assertSame('Key not found', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
        $this->assertInstanceOf(ApiException::class, $exception);
    }
    
    public function testNotFoundExceptionWithKey(): void
    {
        $exception = NotFoundException::forKey('missing_key');
        
        $this->assertStringContainsString('missing_key', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
    }
    
    public function testConnectionException(): void
    {
        $exception = new ConnectionException('Connection failed', 0);
        
        $this->assertSame('Connection failed', $exception->getMessage());
        $this->assertInstanceOf(ApiException::class, $exception);
    }
    
    public function testConnectionExceptionForHost(): void
    {
        $exception = ConnectionException::forHost('localhost', 8080);
        
        $this->assertStringContainsString('localhost', $exception->getMessage());
        $this->assertStringContainsString('8080', $exception->getMessage());
    }
    
    public function testTimeoutException(): void
    {
        $exception = new TimeoutException('Operation timed out', 408);
        
        $this->assertSame('Operation timed out', $exception->getMessage());
        $this->assertSame(408, $exception->getCode());
        $this->assertInstanceOf(ApiException::class, $exception);
    }
    
    public function testTimeoutExceptionWithDuration(): void
    {
        $exception = TimeoutException::afterSeconds(30);
        
        $this->assertStringContainsString('30', $exception->getMessage());
        $this->assertSame(408, $exception->getCode());
    }
    
    public function testAuthenticationException(): void
    {
        $exception = new AuthenticationException('Invalid credentials', 401);
        
        $this->assertSame('Invalid credentials', $exception->getMessage());
        $this->assertSame(401, $exception->getCode());
        $this->assertInstanceOf(ApiException::class, $exception);
    }
    
    public function testAuthenticationExceptionForInvalidToken(): void
    {
        $exception = AuthenticationException::invalidToken();
        
        $this->assertStringContainsString('token', $exception->getMessage());
        $this->assertSame(401, $exception->getCode());
    }
    
    public function testAuthenticationExceptionForInvalidCredentials(): void
    {
        $exception = AuthenticationException::invalidCredentials();
        
        $this->assertStringContainsString('password', $exception->getMessage());
        $this->assertSame(401, $exception->getCode());
    }
    
    public function testExceptionChaining(): void
    {
        $root = new \RuntimeException('Root cause');
        $connection = new ConnectionException('Connection lost', 0, $root);
        $api = new ApiException('API failure', 500, $connection);
        
        $this->assertSame($connection, $api->getPrevious());
        $this->assertSame($root, $connection->getPrevious());
        
        // Test exception chain traversal
        $current = $api;
        $messages = [];
        
        while ($current !== null) {
            $messages[] = $current->getMessage();
            $current = $current->getPrevious();
        }
        
        $this->assertSame(['API failure', 'Connection lost', 'Root cause'], $messages);
    }
    
    public function testExceptionContext(): void
    {
        $exception = new ApiException('Context test', 400);
        
        // Test that exception provides useful context for debugging
        $this->assertNotEmpty($exception->getFile());
        $this->assertGreaterThan(0, $exception->getLine());
        $this->assertIsArray($exception->getTrace());
    }
    
    public function testExceptionSerialization(): void
    {
        $exception = new NotFoundException('Serialization test');
        
        // Exceptions should be serializable for logging/caching
        $serialized = serialize($exception);
        $unserialized = unserialize($serialized);
        
        $this->assertSame($exception->getMessage(), $unserialized->getMessage());
        $this->assertSame($exception->getCode(), $unserialized->getCode());
    }
}
