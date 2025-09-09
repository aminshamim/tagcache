<?php

namespace TagCache\Integrations\CodeIgniter;

use TagCache\Client;
use TagCache\Config;

class TagCache
{
    public static function make(array $cfg = []): Client
    {
        return new Client(new Config($cfg));
    }
}
