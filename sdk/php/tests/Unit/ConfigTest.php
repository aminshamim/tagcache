<?php declare(strict_types=1);

namespace TagCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TagCache\Config;

/**
 * @covers \TagCache\Config
 */
final class ConfigTest extends TestCase
{
    public function testFromEnvDefaults(): void
    {
        $cfg = Config::fromEnv([]);
        $this->assertSame('http://localhost:8080', $cfg->http['base_url']);
        $this->assertSame(5000, $cfg->http['timeout_ms']);
    }
    
    public function testHttpMode(): void
    {
        $config = new Config([
            'mode' => 'http',
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 3000,
            ],
        ]);
        
        $this->assertSame('http', $config->mode);
        $this->assertSame('http://localhost:8080', $config->http['base_url']);
        $this->assertSame(3000, $config->http['timeout_ms']);
    }
    
    public function testTcpMode(): void
    {
        $config = new Config([
            'mode' => 'tcp',
            'tcp' => [
                'host' => 'localhost',
                'port' => 1984,
                'timeout_ms' => 3000,
            ],
        ]);
        
        $this->assertSame('tcp', $config->mode);
        $this->assertSame('localhost', $config->tcp['host']);
        $this->assertSame(1984, $config->tcp['port']);
        $this->assertSame(3000, $config->tcp['timeout_ms']);
    }
    
    public function testWithAuthentication(): void
    {
        $config = new Config([
            'mode' => 'http',
            'http' => [
                'base_url' => 'http://localhost:8080',
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'secret',
            ],
        ]);
        
        $this->assertSame('admin', $config->auth['username']);
        $this->assertSame('secret', $config->auth['password']);
    }
    
    public function testEnvironmentVariables(): void
    {
        // Save original values
        $originalUrl = $_ENV['TAGCACHE_HTTP_URL'] ?? null;
        $originalToken = $_ENV['TAGCACHE_TOKEN'] ?? null;
        
        // Set test values
        $_ENV['TAGCACHE_HTTP_URL'] = 'http://test:9090';
        $_ENV['TAGCACHE_TOKEN'] = 'test-token';
        putenv('TAGCACHE_HTTP_URL=http://test:9090');
        putenv('TAGCACHE_TOKEN=test-token');
        
        $config = Config::fromEnv();
        
        $this->assertSame('http://test:9090', $config->http['base_url']);
        $this->assertSame('test-token', $config->auth['token']);
        
        // Restore original values
        if ($originalUrl !== null) {
            $_ENV['TAGCACHE_HTTP_URL'] = $originalUrl;
            putenv("TAGCACHE_HTTP_URL=$originalUrl");
        } else {
            unset($_ENV['TAGCACHE_HTTP_URL']);
            putenv('TAGCACHE_HTTP_URL');
        }
        
        if ($originalToken !== null) {
            $_ENV['TAGCACHE_TOKEN'] = $originalToken;
            putenv("TAGCACHE_TOKEN=$originalToken");
        } else {
            unset($_ENV['TAGCACHE_TOKEN']);
            putenv('TAGCACHE_TOKEN');
        }
    }
}
