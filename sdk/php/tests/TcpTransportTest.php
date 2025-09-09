<?php

use PHPUnit\Framework\TestCase;
use TagCache\Config;
use TagCache\Transport\TcpTransport;

final class TcpTransportTest extends TestCase
{
    public function testBuilds(): void
    {
        $t = new TcpTransport(new Config(['tcp'=>['host'=>'127.0.0.1','port'=>1984,'timeout_ms'=>500]]));
        $this->assertInstanceOf(TcpTransport::class, $t);
    }
}
