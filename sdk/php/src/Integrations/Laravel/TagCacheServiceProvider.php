<?php

namespace TagCache\Integrations\Laravel;

use Illuminate\Support\ServiceProvider as Base;
use TagCache\Client;
use TagCache\Config;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Store;
use TagCache\Integrations\Laravel\TagCacheStore;

class TagCacheServiceProvider extends Base
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/tagcache.php', 'tagcache');

        $this->app->singleton(Client::class, function ($app) {
            $cfgArr = config('tagcache');
            $config = new Config($cfgArr ?? []);
            return new Client($config);
        });

        // Alias 'tagcache' to client
        $this->app->alias(Client::class, 'tagcache');

        // Register cache driver extension
        $this->app->resolving('cache', function ($manager) {
            // $manager is CacheManager
            $manager->extend('tagcache', function ($app) {
                /** @var Client $client */
                $client = $app->make(Client::class);
                $prefix = config('cache.prefix', 'laravel');
                $store = new TagCacheStore($client, $prefix.':');
                return new CacheRepository($store);
            });
        });
    }

    public function boot(): void
    {
        // Allow publishing config
        $this->publishes([
            __DIR__ . '/../../config/tagcache.php' => config_path('tagcache.php'),
        ], 'config');
    }
}
