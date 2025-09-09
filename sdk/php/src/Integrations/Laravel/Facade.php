<?php

namespace TagCache\Integrations\Laravel;

use Illuminate\Support\Facades\Facade as BaseFacade;
use TagCache\Client;

class Facade extends BaseFacade
{
    protected static function getFacadeAccessor()
    {
        return Client::class;
    }
}
