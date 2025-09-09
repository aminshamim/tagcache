<?php

namespace TagCache\SDK\Integrations\CodeIgniter;

use TagCache\SDK\Client;
use TagCache\SDK\Config;

class TagCache
{
    public static function make(array $cfg = []): Client
    {
        return new Client(new Config($cfg));
    }
}
