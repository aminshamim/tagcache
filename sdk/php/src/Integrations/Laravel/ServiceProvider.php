<?php

namespace TagCache\Integrations\Laravel;

use Illuminate\Support\ServiceProvider as Base;
use TagCache\Client;
use TagCache\Config;

class ServiceProvider extends Base
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/tagcache.php', 'tagcache');

        $this->app->singleton(Client::class, function ($app) {
            $cfgArr = config('tagcache');
            $config = new Config($cfgArr ?? []);
            return new Client($config);
        });

        // Alias 'tagcache' to client
        $this->app->alias(Client::class, 'tagcache');
    }

    public function boot(): void
    {
        // Allow publishing config
        $this->publishes([
            __DIR__ . '/../../../config/tagcache.php' => config_path('tagcache.php'),
        ], 'config');
    }
}
