<?php

require __DIR__.'/../vendor/autoload.php';

use TagCache\SDK\Client;
use TagCache\SDK\Config;

$cfg = Config::fromEnv([
  'http' => [
    'base_url' => 'http://127.0.0.1:8080',
    'timeout_ms' => 5000
  ],
  'auth' => [ 'token' => getenv('TAGCACHE_TOKEN') ]
]);

$client = new Client($cfg);
$client->put('demo:key', ['hello' => 'world'], 10000, ['demo']);
$item = $client->get('demo:key');
var_dump($item);
