<?php

namespace TagCache\SDK\Integrations\Laravel;

use Illuminate\Support\Facades\Facade as BaseFacade;
use TagCache\SDK\Client;

class Facade extends BaseFacade
{
    protected static function getFacadeAccessor()
    {
        return Client::class;
    }
}
