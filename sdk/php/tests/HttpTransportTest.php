<?php

use PHPUnit\Framework\TestCase;
use TagCache\SDK\Config;
use TagCache\SDK\Transport\HttpTransport;

final class HttpTransportTest extends TestCase
{
    public function testBuilds(): void
    {
        $t = new HttpTransport(new Config(['http'=>['base_url'=>'http://localhost:8080','timeout_ms'=>1000]]));
        $this->assertInstanceOf(HttpTransport::class, $t);
    }
}
