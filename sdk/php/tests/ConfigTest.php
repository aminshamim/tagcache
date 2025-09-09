<?php

use PHPUnit\Framework\TestCase;
use TagCache\Config;

final class ConfigTest extends TestCase
{
    public function testFromEnvDefaults(): void
    {
        $cfg = Config::fromEnv([]);
        $this->assertSame('http://localhost:8080', $cfg->http['base_url']);
        $this->assertSame(5000, $cfg->http['timeout_ms']);
    }
}
