<?php

namespace TagCache\Integrations\CodeIgniter;

use TagCache\Client;
use TagCache\Config;

class TagCache
{
    /**
     * @param array<string, mixed> $cfg
     */
    public static function make(array $cfg = []): Client
    {
        return new Client(new Config($cfg));
    }
}
